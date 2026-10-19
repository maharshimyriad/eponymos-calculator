# Eponymos Questionnaire

A data-driven WordPress questionnaire, pricing calculator, submission snapshot and report foundation.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- ACF Pro is detected and used as a dependency boundary; application data is stored in normalized custom tables.

Use `[eponymos_questionnaire]` to render the configured questionnaire. The plugin seeds the current Quick Estimate questionnaire on activation when no questionnaire exists. The Stage 1 sample does not define a documented mapping from answers to active user days, so the initial mapping is explicit configuration (`eponymos_pricing_config`) and defaults to the configured user-band value times the configured usage days.
