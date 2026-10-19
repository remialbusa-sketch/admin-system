<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regression sentinels for the dashboard grid editor's resize robustness
 * (resources/js/dashboard-grid.js). The editor must survive the cursor
 * leaving the viewport mid-drag and must never snap a released resize back
 * to the pre-drag size; these scans pin every guard so a future refactor
 * cannot silently drop one.
 */
class DashboardGridResizeGuardTest extends TestCase
{
    protected function editor(): string
    {
        $source = file_get_contents(resource_path('js/dashboard-grid.js'));
        $this->assertNotFalse($source);

        return $source;
    }

    public function test_a_resize_captures_the_pointer_and_ends_on_every_interruption(): void
    {
        $editor = $this->editor();

        $this->assertStringContainsString(
            'handle.setPointerCapture(event.pointerId)',
            $editor,
            'Without pointer capture the browser stops delivering pointermove once the cursor leaves the viewport (dragging a height grip pulls toward the window edge), which froze the resize mid-drag.'
        );

        foreach ([
            "document.addEventListener('pointerup', endResize);",
            "document.addEventListener('pointercancel', endResize);",
            "document.addEventListener('lostpointercapture', endResize);",
            "window.addEventListener('blur', endResize);",
        ] as $listener) {
            $this->assertStringContainsString($listener, $editor, $listener.' must end the resize session.');
        }

        $this->assertStringContainsString(
            'event.pointerId !== resizing.pointerId',
            $editor,
            'A second pointer must not drive an armed resize.'
        );

        $this->assertStringContainsString(
            'event.button !== 0',
            $editor,
            'A non-primary button must not arm a resize (a right-click context menu swallows the pointerup).'
        );
    }

    public function test_a_native_drag_cannot_hijack_an_armed_resize(): void
    {
        $this->assertStringContainsString(
            'if (resizing || !isEditing() || !section) {',
            $this->editor(),
            'dragstart must bail out while a resize is armed: touch drag-and-drop fires dragstart even though pointerdown was canceled, and a native drag freezes all pointer events.'
        );
    }

    public function test_released_spans_wait_for_the_morph_before_clearing(): void
    {
        $editor = $this->editor();

        $this->assertStringContainsString(
            "section.dataset.spanPending = '1';",
            $editor,
            'Released optimistic spans must be flagged for the morph cleanup instead of being cleared at pointerup (clearing snapped the widget back to its old size).'
        );

        $this->assertStringContainsString(
            "window.Livewire.hook('morphed'",
            $editor,
            'The Livewire morph hook is the only place that may clear pending spans — after the server rerender arrived.'
        );

        $this->assertStringContainsString(
            'clearPendingSpans(section);',
            $editor,
            'A new drag on the same widget must self-heal a stale pending span.'
        );
    }
}
