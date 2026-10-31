<?php

declare(strict_types=1);

it('renders the elements index as home page', function (): void {
    $page = visit('/');

    $page->assertSee('Éléments')->assertNoJavaScriptErrors();
});
