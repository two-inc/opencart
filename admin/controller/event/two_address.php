<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Core\CompanyCapture;

/**
 * The admin's customer address edit (TWO-26721): its form posts only the
 * configured custom fields, and OpenCart stores exactly what is posted, so
 * the company a buyer captured with the address would be wiped. Keep it,
 * while the address's company is still its name.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\Admin\Model\Customer\Customer $model_customer_customer
 */
class TwoAddress extends \Opencart\System\Engine\Controller
{
    /** @param array<int, mixed> $args editAddress(customer_id, address_id, data) */
    public function index(string &$route = '', array &$args = []): void
    {
        if (!is_array($args[2] ?? null)) {
            return;
        }
        $this->load->model('customer/customer');
        $stored = $this->model_customer_customer->getAddress((int) ($args[1] ?? 0));
        $data = $args[2];
        $data['custom_field'] = CompanyCapture::keep(
            is_array($data['custom_field'] ?? null) ? $data['custom_field'] : [],
            (string) ($data['company'] ?? ''),
            [],
            [],
            $stored,
            (int) ($args[0] ?? 0),
        );
        $args[2] = $data;
    }
}
