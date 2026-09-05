{{--
    A control of the application's own, in the toolbar.start slot.

    Nothing here is a package class except the button's, borrowed so it matches
    the toolbar's own buttons; the slot marker is display: contents, so this
    joins the toolbar's flex row rather than sitting in a box of its own.
--}}
<a class="dynamic-table-button" href="{{ route('examples.index') }}">
    <span aria-hidden="true">←</span> All examples
</a>
