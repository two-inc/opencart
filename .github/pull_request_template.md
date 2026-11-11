## Why

## What

## Testing

## Parity checklist

From the Plugin Build Guide. Tick each line, or say why it does not apply. A reviewer refuses the PR if a line is unticked without a reason.

- [ ] The same change is open or merged on magento-plugin, woocommerce-plugin and prestashop-plugin (and magento-hyva-extension where it touches checkout). Links: ...
- [ ] Settings: same key meaning, same stored values (exact strings), same default on fresh install, same validation rule, same error text.
- [ ] Admin: same label, caption, description, section or tab name, option labels and option order, sentence case.
- [ ] Upgrade: existing installs on every platform end up in the same state; migration for each platform's legacy values named.
- [ ] Buyer-facing: same copy, same placement, same show/hide and withhold rules, on every renderer of every platform, including the OpenCart 4 checkout.
- [ ] Tests: the shared case table (if the feature has one) is byte-identical across repos and run by each suite.
- [ ] Any difference that remains is in the Divergence register with approval, or in Open follow-ups with a ticket.
- [ ] The guide is updated in the same change if behaviour changed.
