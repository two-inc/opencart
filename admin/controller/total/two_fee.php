<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Total;

/**
 * Admin page of the payment terms fee total (Extensions > Order Totals):
 * its status and sort order. The fee itself and its tax class are set with
 * the payment method, on the Payment terms tab, as core's shipping total
 * leaves the tax class to the shipping method.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Document $document
 * @property-read \Opencart\System\Library\Url $url
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\System\Library\Cart\User $user
 * @property-read \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 */
class TwoFee extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/total/two_fee';

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $token = 'user_token=' . $this->session->data['user_token'];
        $data = [
            'breadcrumbs' => [
                ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
                ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=total')],
                ['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
            ],
            'save' => $this->url->link(self::ROUTE . '.save', $token),
            'back' => $this->url->link('marketplace/extension', $token . '&type=total'),
            'total_two_fee_status' => $this->config->get('total_two_fee_status'),
            'total_two_fee_sort_order' => $this->config->get('total_two_fee_sort_order'),
            'help_sort_order' => sprintf($this->language->get('help_sort_order'), $this->taxSortOrder()),
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
        ];

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        $this->load->language(self::ROUTE);
        $json = [];
        $sort = trim((string) ($this->request->post['total_two_fee_sort_order'] ?? ''));
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        } elseif (!ctype_digit($sort) || (int) $sort >= $this->taxSortOrder()) {
            $json['error']['sort-order'] = sprintf($this->language->get('error_sort_order'), $this->taxSortOrder());
        } else {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('total_two_fee', [
                'total_two_fee_status' => ($this->request->post['total_two_fee_status'] ?? '0') === '1' ? '1' : '0',
                'total_two_fee_sort_order' => $sort,
            ]);
            $json['success'] = $this->language->get('text_success');
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    private function taxSortOrder(): int
    {
        return (int) $this->config->get('total_tax_sort_order');
    }
}
