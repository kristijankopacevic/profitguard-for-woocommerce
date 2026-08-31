# Monetisation — later, and only if the evidence says so

**Nothing in this document is implemented.** There is no licence check, no
feature gate, no upgrade nag, no trial timer and no billing code anywhere in
this plugin, and there must not be. This is a plan to be executed *after*
validation, not a roadmap the free plugin hints at.

---

## The conditions

Do not start until **all** of these are true (see `VALIDATION_PLAN.md`):

- 90 days listed
- 300+ active installs
- 4.0+ average rating with at least 3 written reviews
- **5+ support threads about importing costs or carrier invoices** — the one
  that proves people are doing the work, not just installing
- At least 3 merchants who can describe a decision they changed because of a
  finding

The fifth condition is the real gate. Everything else is traffic.

---

## What the free plugin must keep, permanently

This is a promise, not a phase. Breaking it after the fact is the fastest way to
turn a 4.5-star listing into a 2-star one, and the WordPress.org guidelines
prohibit most of it anyway.

- Every finding the free plugin can calculate, it calculates. All of them,
  always. **No "20 findings free, pay for the rest".**
- Product cost import, carrier cost import, the full scan, the score, and the
  CSV export stay free and unlimited.
- No feature stops working after a period of time or a number of uses.
- No upgrade nag on the WordPress dashboard, no ads in the admin, no notice
  outside ProfitGuard's own screens.
- No telemetry, ever, without an explicit opt-in the merchant actively chooses.

A paid tier that removes something merchants already have is not a paid tier, it
is a hostage situation. Pro must sell something that does not exist today.

---

## What Pro could plausibly be

Pro ships as a **separate plugin** from a separate site, requiring the free one.
It has never been in the directory and never will be — the directory does not
host paid plugins, and the free plugin must not contain a line of its code.

Candidates, in the order the forum is most likely to justify them:

1. **History and trend.** The free plugin scores today. Pro keeps a scan series
   and shows margin drift, cost creep per supplier, and shipping profitability
   over months. This is the most-requested thing in every tool of this shape,
   and it is genuinely additive — it needs retained data the free plugin
   deliberately prunes.
2. **Scheduled scans with email digests.** Weekly "three products fell below
   target" mail. Additive; the free plugin scans on demand.
3. **Purchase-order and supplier cost tracking.** Cost per supplier over time,
   landed cost with duty and freight apportioned. A different data model, not a
   gate on an existing one.
4. **Multi-currency with real rate history.** Needs a rate source, which needs a
   paid API, which is precisely why it cannot be in a zero-cost free plugin.
5. **Bulk price actions.** Apply a recommended price to a filtered set, with a
   preview and an undo. The free plugin recommends; Pro would act.

Note the shape: each one costs *ongoing money or ongoing work to run*. That is
what makes a subscription honest rather than rent on code already written.

---

## Price

**€29/year** for a single site, if Pro is mostly history and digests.
**€49/year** if it includes purchase orders or bulk actions.

Reasons for that range rather than a higher one:

- The buyer is a small WooCommerce merchant, not an agency. Their whole plugin
  budget is often under €200/year.
- The competing option is a spreadsheet and an afternoon, which is free.
- Renewal at €29 is a decision nobody escalates. At €99 it becomes one.

Annual, not monthly: the value is realised in a yearly rhythm (supplier price
lists, carrier contract renewals), and monthly billing on a €3 product costs
more in payment fees and churn handling than it earns.

Offer a 5-site tier at roughly 3× the single price for the agencies who will ask.

---

## Billing

**Stripe**, when the time comes. Not now.

- No monthly fee; ~1.5% + €0.25 on European cards. You pay only when paid.
- Stripe Payment Links plus the Customer Portal covers checkout, VAT, invoices,
  card updates and cancellation with no billing UI of your own.
- Stripe Tax handles EU VAT and OSS for a small percentage — worth it, because
  getting EU VAT wrong on digital goods is expensive.

The licence server is the only real build: a small endpoint that maps a Stripe
subscription to a key and answers "is this key valid for this domain". Keep it
boring, cache the answer for a week, and **fail open** — a merchant whose
licence check times out must keep their software working. Software that stops
working because a server is down is how you earn the reviews that end this.

---

## What must never happen

- Adding a gate to a free feature that already shipped.
- A dashboard notice advertising Pro.
- A "trial" that disables things when it ends.
- Telemetry justified as "to improve the product".
- Shipping any of it inside the WordPress.org plugin.

---

## And the honest caveat

None of this is a revenue projection and none of it is promised. Most free
WordPress plugins never reach 300 active installs, and most that do never
convert at a rate that makes a paid tier worth maintaining. The validation plan
exists precisely so that the answer "this is not a business" arrives after 90
days of a listing that cost nothing, rather than after six months of building a
licence server.
