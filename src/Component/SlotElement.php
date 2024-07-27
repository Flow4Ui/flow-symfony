<?php

namespace Flow\Component;

class SlotElement extends TemplateElement
{
    public function __construct(
        public string|null $slot = null,
                           $props = [],
    )
    {
        parent::__construct('Slot', $props);
    }


    public function render(Context|null $context = null): string
    {
        if (empty($this->slot)) {
            if (empty($this->props['name'])) {
                $slot = '"default"';
            } else {
                $slot = $this->renderValue($this->props['name'], $context);
                unset($this->props['name']);
            }
        } else {
            $slot = json_encode($this->slot);
        }
        $renderedChildren = implode(',', $this->renderChildren($context));
        return !empty($renderedChildren) ? sprintf(
            'v.renderSlot(this.$slots,%s, %s, ()=>[%s])',
            $slot,
            ($this->renderProps($context)),
            $renderedChildren,
        ) : sprintf(
            'v.renderSlot(this.$slots,%s, %s)',
            $slot,
            ($this->renderProps($context))
        );

    }
}