<?php

namespace Tests\Unit;

use Tests\TestCase;

class ImportWizardAlpineNullSafetyTest extends TestCase
{
    /**
     * The wizard threw Alpine Expression Errors in production
     * ("Cannot read properties of undefined/null") when a popup closed or a
     * name box was removed: template expressions dereferenced typeMenu,
     * optionsMenu, newCols[letter] and importCols[letter] one render tick
     * after the object was gone. Every such read in the template region must
     * be null-safe (?. or guarded bindings) — x-if teardown re-evaluates
     * inner expressions after the guard flips.
     */
    public function test_wizard_template_has_no_unguarded_nullable_alpine_reads(): void
    {
        $path = resource_path('views/import/classic.blade.php');
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        // Only the template region: the <script> Alpine methods guard with
        // explicit `this.typeMenu &&` checks, which is safe there.
        $template = strstr($source, '<script>', true);
        $this->assertNotFalse($template);

        $this->assertDoesNotMatchRegularExpression(
            '/typeMenu\.(letter|left|top)/',
            $template,
            'Unguarded typeMenu.* read in the wizard template (use typeMenu?.).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/optionsMenu\.(letter|left|top)/',
            $template,
            'Unguarded optionsMenu.* read in the wizard template (use optionsMenu?.).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/x-model="(newCols|importCols)\[col\.letter\]\./',
            $template,
            'x-model on a deletable nested object in the wizard template (use null-safe :value/@input or :checked/@change).'
        );
    }
}
