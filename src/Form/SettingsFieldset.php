<?php declare(strict_types=1);

namespace LockEdit\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Lock Edit'; // @translate

    public function init(): void
    {
        $this
            ->setAttribute('id', 'lock-edit')

            ->add([
                'name' => 'lockedit_disable',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Disable content lock to allow concurrent editing', // @translate
                ],
                'attributes' => [
                    'id' => 'lockedit_disable',
                ],
            ])
            ->add([
                'name' => 'lockedit_duration',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Number of seconds before automatic removing of the lock', // @translate
                ],
                'attributes' => [
                    'id' => 'lockedit_duration',
                ],
            ])
        ;
    }
}
