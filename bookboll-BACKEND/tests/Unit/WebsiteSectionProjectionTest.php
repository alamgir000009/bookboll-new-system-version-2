<?php

use App\Services\BrandResolutionService as Brand;

/**
 * The provider dashboard saves `site_pages.home.sections` and then re-reads the
 * PUBLIC brand response to confirm the change applied. Those two have to agree
 * on the keys and on what an unsaved value means, which is what this covers:
 * the projection used to omit `sections` entirely, so a hidden locations
 * section kept showing on the live site.
 */
it('reports every known section, defaulting an unsaved one to visible', function () {
    $out = Brand::homeSections([]);

    expect(array_keys($out))->toBe(Brand::HOME_SECTIONS)
        ->and($out)->each->toBeTrue();
});

it('hides only the sections explicitly switched off', function () {
    $out = Brand::homeSections(['sections' => ['locations' => false, 'blog' => true]]);

    expect($out['locations'])->toBeFalse()
        ->and($out['blog'])->toBeTrue()
        ->and($out['contact'])->toBeTrue();
});

it('reads a legacy string flag as a real boolean, never as a truthy string', function () {
    $out = Brand::homeSections(['sections' => ['locations' => 'false', 'blog' => 'true']]);

    expect($out['locations'])->toBeFalse()
        ->and($out['blog'])->toBeTrue();

    foreach ($out as $flag) {
        expect($flag)->toBeBool();
    }
});

it('keeps an unreadable or null value visible rather than hiding content', function () {
    $out = Brand::homeSections(['sections' => ['locations' => null, 'about' => 'maybe']]);

    expect($out['locations'])->toBeTrue()
        ->and($out['about'])->toBeTrue();
});

it('ignores a malformed sections value and still returns the full shape', function () {
    $out = Brand::homeSections(['sections' => 'nonsense', 'hero_image' => 'x.png']);

    expect(array_keys($out))->toBe(Brand::HOME_SECTIONS)
        ->and($out)->each->toBeTrue();
});

it('matches the dashboard list of editable sections', function () {
    // admin/src/lib/websiteSections.js WEBSITE_SECTIONS — keep both in step.
    expect(Brand::HOME_SECTIONS)
        ->toBe(['hero', 'listings', 'programs', 'about', 'blog', 'locations', 'contact']);
});
