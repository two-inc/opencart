<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Total;

/**
 * The payment terms fee as an OpenCart order total (Build Guide section 5),
 * the way core's handling total adds a taxed charge: the net is the buyer's
 * share Two quotes for the chosen term, and its tax is what the shop's own
 * tax engine gives the tax class the merchant selected for the fee. The
 * plugin never picks a rate.
 *
 * Added only while Two is the chosen payment method. Its sort order must
 * come before the Taxes total, which lists the taxes added so far.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Cart\Tax $tax
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class TwoFee extends \Opencart\System\Engine\Model
{
    /**
     * @param array<int, array<string, mixed>> $totals
     * @param array<int, float>                $taxes
     */
    public function getTotal(array &$totals, array &$taxes, float &$total): void
    {
        $this->load->model('extension/two/payment/two');
        if (\Opencart\Catalog\Model\Extension\Two\Payment\Two::feeSuspended()) {
            return;
        }
        $fee = $this->model_extension_two_payment_two->feeTotal();
        if ($fee === null) {
            return;
        }
        $totals[] = [
            'extension' => 'two',
            'code' => 'two_fee',
            'title' => $fee['title'],
            'value' => $fee['value'],
            'sort_order' => (int) $this->config->get('total_two_fee_sort_order'),
        ];
        // A placed fee put back on an edit carries its own tax rows; a new fee is taxed by the shop's engine.
        $rows = $fee['taxes'] ?? null;
        if ($rows === null && $fee['tax_class_id'] > 0) {
            $rows = [];
            foreach ($this->tax->getRates($fee['value'], $fee['tax_class_id']) as $rate) {
                $rows[(int) $rate['tax_rate_id']] = ['amount' => (float) $rate['amount'], 'name' => (string) $rate['name']];
            }
        }
        foreach ($rows ?? [] as $id => $row) {
            if (!$this->tax->getRateName($id) && $row['name'] !== '') {
                // A rate deleted since the order was placed: its own Taxes row, under the name it had.
                $totals[] = ['extension' => 'two', 'code' => 'tax', 'title' => $row['name'], 'value' => $row['amount'], 'sort_order' => (int) $this->config->get('total_tax_sort_order')];
                $total += $row['amount'];
                continue;
            }
            $taxes[$id] = ($taxes[$id] ?? 0.0) + $row['amount'];
        }
        $total += $fee['value'];
    }
}
