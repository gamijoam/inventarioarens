<?php

return [
    // The production CSP disallows eval(), so Livewire must use its CSP-safe
    // frontend bundle. This also covers Filament's Livewire login panel.
    'csp_safe' => true,

    // Bump the URL so browsers don't reuse the non-CSP bundle cached for a year.
    'asset_url' => class_exists(\Livewire\Mechanisms\HandleRequests\EndpointResolver::class)
        ? \Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix().'/livewire.min.js?build=csp-safe-1'
        : null,
];
