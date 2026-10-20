<?php

namespace App\Livewire;

abstract class Component extends \Livewire\Component
{
    public function refreshLocale(): void
    {
        $this->resetValidation();

        foreach (get_object_vars($this) as $property => $value) {
            if (is_string($value) && preg_match('/(?:message|notice)$/i', $property)) {
                $this->{$property} = '';
            }
        }
    }
}
