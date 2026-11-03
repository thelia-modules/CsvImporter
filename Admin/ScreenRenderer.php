<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvImporter\Admin;

use Thelia\Core\Template\Parser\ParserResolver;

/**
 * Renders a template of the module through the parser of the back-office theme.
 *
 * The raw Twig environment does not know the template directories of the modules: only
 * the parser of Thelia adds them to the loader when it takes the theme in charge.
 */
final readonly class ScreenRenderer
{
    public function __construct(private ParserResolver $parsers)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(string $template, array $parameters = []): string
    {
        $definition = $this->parsers->getCurrentTemplateDefinition();

        foreach ($this->parsers->getParsers() as $parser) {
            if (!str_ends_with($template, '.'.$parser->getFileExtension())) {
                continue;
            }

            if (null !== $definition && null === $parser->getTemplateDefinition()) {
                $parser->setTemplateDefinition($definition, true);
            }

            return $parser->render($template, $parameters);
        }

        throw new \LogicException('The back-office theme in use cannot render the screens: they need default-twig');
    }
}
