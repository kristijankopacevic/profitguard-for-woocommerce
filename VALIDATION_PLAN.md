# Validation plan

The purpose of the free plugin is to find out whether merchants have this
problem and whether this tool solves it — before anyone builds a paid product on
top of the assumption that they do.

Every metric below is available for free from WordPress.org, the support forum,
or by asking people. **The plugin itself collects nothing.** There is no
telemetry, no phone-home and no opt-in analytics, so none of these numbers come
from merchants' installations.

---

## Where the numbers come from

| Source | What it gives you | Cost |
| --- | --- | --- |
| `wordpress.org/plugins/profitguard-for-woocommerce/advanced/` | Downloads per day, active installs, version breakdown, WP/PHP version split | Free |
| The plugin's support forum | Every question, in public, with the asker's context | Free |
| The reviews tab | Ratings and written reviews | Free |
| A support email in `readme.txt` | Private reports, usually more detailed | Free |
| Asking a user directly | The only source that tells you *why* | Free |

Active installs are bucketed (10+, 20+, 30+, 50+, 100+…) and lag by a day or
two. Do not read precision into them that is not there.

---

## The funnel, and the honest bit

You can measure three of the five stages for free. **Activation rate and
first-scan rate are not measurable without telemetry, and telemetry is not being
added.** That is a deliberate trade: the plugin's core promise is that store
financials never leave the install, and instrumenting the funnel would
contradict the privacy statement on every admin screen.

| Stage | Measurable? | How |
| --- | --- | --- |
| Downloads | Yes | Directory statistics |
| Active installs | Yes | Directory statistics |
| Activation rate | **No** | Would need telemetry. Proxy: active installs ÷ downloads |
| Ran a first scan | **No** | Would need telemetry. Proxy: support threads and reviews that describe results |
| Imported cost or carrier data | **No** | Proxy: import questions in the forum — the highest-signal thread type there is |

The proxy `active installs ÷ downloads` is rough — it undercounts because
installs are bucketed and lag, and overcounts nothing. Treat it as a trend, not
a rate.

---

## Thresholds, at 90 days after listing

Judge at 90 days, not before. The first three weeks of a listing are dominated
by the "new plugins" feed and tell you about the feed, not the product.

| Signal | Weak | Worth continuing | Strong |
| --- | --- | --- | --- |
| Downloads | < 300 | 300 – 1,500 | > 1,500 |
| Active installs | < 50 | 50 – 300 | > 300 |
| Installs ÷ downloads | < 20% | 20 – 40% | > 40% |
| Written reviews | 0 | 1 – 4 | 5+ |
| Average rating | < 4.0 | 4.0 – 4.5 | > 4.5 |
| Support threads | 0 | 3 – 15 | 15+ |
| Threads about **import** specifically | 0 | 2+ | 5+ |
| Unanswered threads (yours) | any | 0 | 0 |

Two rows deserve explanation because they read backwards:

**Zero support threads is a weak signal, not a strong one.** It usually means
nobody got far enough to hit a rough edge. A plugin with 200 installs and no
questions is a plugin nobody is using.

**Import questions are the single best signal in the table.** Importing supplier
costs is work. A merchant who exports a CSV from their supplier, maps the
columns and imports it has told you, with their time, that they want the answer
badly enough to pay for it. That is worth more than a hundred passive installs.

**Unanswered threads must be zero, always.** An unanswered thread is the most
visible negative signal a plugin page carries, and it is entirely within your
control.

---

## What to actually do with the answers

Ask, in the forum or by email, of anyone who engages:

1. What did the score tell you that you did not already know?
2. Did you import costs? If not, what stopped you?
3. What did you do differently because of a finding? (If nothing, the plugin is
   a dashboard, not a tool.)
4. What is the next thing you wanted it to tell you and it could not?

Question 3 is the one that matters. Installs measure curiosity. A merchant who
changed a price or renegotiated a carrier rate because of a finding is the only
evidence that the tool works.

---

## Decision points

**At 90 days, weak across the board** — stop. Do not build Pro. Do not add
features to rescue it. The most likely explanations, in order: merchants do not
know their costs and are not going to enter them; the ones who do already use
their accountant's spreadsheet; or margin is simply not the thing keeping them
awake. Any of those is a real answer and it is much cheaper to accept it now.

**Worth continuing** — do not build Pro yet either. Spend the next quarter on
whatever the forum keeps asking for. The most common request is the roadmap.

**Strong, with import questions** — the conditions in `MONETIZATION_LATER.md`
are met and Pro is worth costing out.

**Strong, but nobody imports** — you have built something people install and do
not use. Fix the import funnel before anything else; a paid tier on top of an
unused free tier sells to nobody.

---

## Timeline

| When | What |
| --- | --- |
| Day 0 | Listed. Subscribe to the support forum. |
| Day 1–14 | Answer every thread within 24 hours. Fix crash reports same week. |
| Day 30 | First read of the numbers. Do not act on them yet. |
| Day 30–60 | Ship one release addressing the most-reported friction. |
| Day 90 | Judge against the table above. |
| Day 90+ | If strong: read `MONETIZATION_LATER.md`. Otherwise: continue or stop. |
