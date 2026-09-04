<?php
/**
 * Prove the shipped code cannot make an outbound request.
 *
 *   php tests/assert-no-outbound-requests.php dist/_verify
 *
 * readme.txt says the plugin "makes no external HTTP requests, contains no
 * analytics or telemetry of any kind, and sends no store data anywhere". That
 * is the highest-value sentence in the file, because it is the one a merchant
 * cannot check for themselves and a reviewer will not take on trust.
 *
 * WHY THIS TOKENIZES INSTEAD OF GREPPING. The first version of this check was
 * `grep -E 'wp_remote_|curl_init|...'` and it failed the build - on a COMMENT
 * in Import/Importer.php that reads "wp_remote_get() is for URLs; WP_Filesystem
 * cannot bound the read", i.e. on prose explaining why the function is NOT
 * used. A check that guards a trust claim must not be satisfied or broken by
 * prose, so this looks at PHP tokens: comments and doc comments are their own
 * token types and are never mistaken for a call.
 *
 * Run against the EXTRACTED ZIP, not the working tree: bin/ and tests/ read
 * local files legitimately and do not ship.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

/**
 * Functions that would make the privacy sentence false.
 *
 * file_get_contents is deliberately absent: it is permitted on a local path,
 * and is checked separately below for a URL argument.
 */
const PG_FORBIDDEN = array(
	'wp_remote_get',
	'wp_remote_post',
	'wp_remote_head',
	'wp_remote_request',
	'wp_safe_remote_get',
	'wp_safe_remote_post',
	'wp_safe_remote_head',
	'wp_safe_remote_request',
	'curl_init',
	'curl_exec',
	'curl_setopt',
	'curl_multi_init',
	'fsockopen',
	'pfsockopen',
	'stream_socket_client',
	'socket_create',
	'socket_connect',
	'dns_get_record',
	'checkdnsrr',
);

$root = $argv[1] ?? 'dist/_verify';
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "ERROR: {$root} is not a directory\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$violations       = array();
$local_file_reads = array();
$scanned          = 0;

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	++$scanned;

	$path   = $file->getPathname();
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	foreach ( $tokens as $index => $token ) {
		// Comments and doc comments are their own token types, so prose can
		// neither trip nor satisfy this check.
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$name = strtolower( $token[1] );
		$line = (int) $token[2];

		// Is this actually a call? Look ahead past whitespace for a paren.
		$next = null;
		for ( $j = $index + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				continue;
			}
			$next = $tokens[ $j ];
			break;
		}
		if ( '(' !== $next ) {
			continue;
		}

		// A method call of the same name is not the global function.
		$previous = null;
		for ( $k = $index - 1; $k >= 0; $k-- ) {
			if ( is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				continue;
			}
			$previous = $tokens[ $k ];
			break;
		}
		if ( is_array( $previous )
			&& in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		if ( in_array( $name, PG_FORBIDDEN, true ) ) {
			$violations[] = sprintf( '%s:%d calls %s()', $path, $line, $name );
			continue;
		}

		// file_get_contents is allowed on a local path. Fail only if a string
		// literal argument looks like a URL.
		if ( 'file_get_contents' === $name || 'file_put_contents' === $name ) {
			$argument = null;
			for ( $j = $index + 2; $j < $count && $j < $index + 8; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				$argument = $tokens[ $j ];
				break;
			}
			if ( is_array( $argument )
				&& T_CONSTANT_ENCAPSED_STRING === $argument[0]
				&& preg_match( '#^.?(https?|ftp)://#i', trim( $argument[1], "'\"" ) ) ) {
				$violations[] = sprintf( '%s:%d %s() on a URL literal', $path, $line, $name );
				continue;
			}
			$local_file_reads[] = sprintf( '%s:%d %s()', $path, $line, $name );
		}
	}
}

printf( 'scanned %d shipped PHP file(s) under %s%s', $scanned, $root, PHP_EOL );

if ( $scanned < 1 ) {
	fwrite( STDERR, "ERROR: no PHP files found; the check would pass vacuously.\n" );
	exit( 1 );
}

if ( ! empty( $local_file_reads ) ) {
	printf( 'local filesystem reads in shipped code (permitted, listed for review):%s', PHP_EOL );
	foreach ( $local_file_reads as $entry ) {
		printf( '  %s%s', $entry, PHP_EOL );
	}
}

if ( ! empty( $violations ) ) {
	fwrite( STDERR, "ERROR: shipped code can make an outbound request, so readme.txt's privacy claim is false:\n" );
	foreach ( $violations as $violation ) {
		fwrite( STDERR, "  {$violation}\n" );
	}
	exit( 1 );
}

printf( 'NO_OUTBOUND_REQUESTS_PASS%s', PHP_EOL );
