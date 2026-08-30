<?php

use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\FeatureSet;

it('enables cheap features by default', function (): void {
    $features = new FeatureSet;

    expect($features->has(Feature::SEARCH))->toBeTrue()
        ->and($features->has(Feature::SORTING))->toBeTrue()
        ->and($features->has(Feature::PAGINATION))->toBeTrue()
        ->and($features->has(Feature::FILTERS))->toBeTrue();
});

it('keeps expensive features off until asked for', function (): void {
    $features = new FeatureSet;

    foreach ([Feature::EXPORT, Feature::IMPORT, Feature::INLINE_EDIT, Feature::VIEWS] as $feature) {
        expect($features->has($feature))->toBeFalse();
    }
});

it('normalises hyphenated and camel cased names', function (): void {
    $features = new FeatureSet(['bulk-actions', 'columnPicker']);

    expect($features->has(Feature::BULK_ACTIONS))->toBeTrue()
        ->and($features->has(Feature::COLUMN_PICKER))->toBeTrue();
});

it('expands implied features', function (): void {
    expect((new FeatureSet(['bulk_actions']))->has(Feature::SELECTION))->toBeTrue()
        ->and((new FeatureSet(['views']))->has(Feature::COLUMN_PICKER))->toBeTrue();
});

it('allows a default feature to be switched off', function (): void {
    expect((new FeatureSet(['-search']))->has(Feature::SEARCH))->toBeFalse();
});

it('supports a minimal set', function (): void {
    $features = new FeatureSet(['only', 'pagination']);

    expect($features->all())->toBe(['pagination']);
});

it('ignores unknown feature names', function (): void {
    expect((new FeatureSet(['teleportation']))->has('teleportation'))->toBeFalse();
});

it('reports only the javascript modules it needs', function (): void {
    expect((new FeatureSet(['only', 'pagination']))->modules())->toBe([])
        ->and((new FeatureSet(['only', 'export']))->modules())->toBe(['transfer']);
});
