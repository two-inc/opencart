<?php

/**
 * Where this plugin declares the Environment setting (TWO-26671). Read only
 * by this repo's dev/environment-parity.php, which extracts the contract from
 * this repo's own code into dev/environment-contract.json; the other plugins
 * compare that JSON as data. After changing this file or the code it names,
 * run `php dev/environment-parity.php --write` and commit the JSON. Runs
 * inside that script and uses its helpers; declare no functions or classes.
 *
 * The rules live in the core's Environment, which never reads the process:
 * the adapter (Services::internalEnvironmentsEnabled) reads
 * TWO_INTERNAL_ENVIRONMENTS and passes it through flagEnabled(), so 'flag'
 * names that method and the options rule takes its answer as an argument.
 */

return [
    'platform' => 'opencart',
    'cases' => 'tests/fixtures/environment-cases.json',
    'helpers' => [
        'file' => 'system/library/core/environment.php',
        'class' => 'Environment',
        'consts' => [
            'modes' => 'ALL',
            'default' => 'DEFAULT',
            'production' => 'PRODUCTION',
            'sandbox' => 'SANDBOX',
            'staging' => 'STAGING',
        ],
        'methods' => ['flagEnabled', 'recognise', 'offered', 'resolve'],
        'options' => 'offered',
        'flag' => 'flagEnabled',
        'read' => 'resolve',
    ],
    // No stored value is rewritten: every read goes through resolve(), so the
    // read path is the migration.
    'migrate' => [
        'method' => 'resolve',
        'variants' => ['every store' => ['args' => [], 'moves' => []]],
    ],
    'declarations' => static function (array $p): array {
        $install = match1(
            "/'environment'\\s*=>\\s*([^,\\n]+?)\\s*,/",
            $p['file']('system/library/core/settings_provider.php'),
            "the 'environment' default in SettingsProvider"
        );
        $strings = languageStrings($p['file']('admin/language/en-gb/payment/two.php'));
        $labels = [];
        foreach (['production', 'sandbox', 'staging'] as $mode) {
            $labels[$mode] = $strings['text_environment_' . $mode]
                ?? throw new RuntimeException("no text_environment_{$mode} in the en-gb admin language file");
        }
        return [
            'install_default' => [$p['value']($install[1])],
            'labels' => $labels,
            'field_label' => [$strings['entry_environment'] ?? throw new RuntimeException('no entry_environment')],
            'description' => [$strings['help_environment'] ?? throw new RuntimeException('no help_environment')],
        ];
    },
];
