<?php

declare(strict_types=1);

namespace Kajona\System\System\Modelaction\Provider;

use Kajona\System\System\Modelaction\Container\ModelActionsContainerInterface;

/**
 * @since 7.2
 */
final class InlineModelActionsContainerProvider implements ModelActionsContainerProviderInterface
{
    /**
     * @var string
     */
    private $modelClassName;

    /**
     * @var ModelActionsContainerInterface
     */
    private $modelActionsContainer;

    public function __construct(string $modelClassName, ModelActionsContainerInterface $modelActionsContainer)
    {
        $this->modelClassName = $modelClassName;
        $this->modelActionsContainer = $modelActionsContainer;
    }

    public function getModelClassName(): string
    {
        return $this->modelClassName;
    }

    public function getModelActionsContainer(): ModelActionsContainerInterface
    {
        return $this->modelActionsContainer;
    }
}
