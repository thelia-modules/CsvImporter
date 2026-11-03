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

namespace CsvImporter\Lookup;

use CsvImporter\Mapping\CategoryPath;
use CsvImporter\Report\Refusal;
use CsvImporter\Report\ReportMessage;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Feature\FeatureAvCreateEvent;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\Template\TemplateAddFeatureEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Catalog\Brand\BrandFacade;
use Thelia\Domain\Catalog\Brand\DTO\BrandCreateDTO;
use Thelia\Domain\Catalog\Category\CategoryFacade;
use Thelia\Domain\Catalog\Category\DTO\CategoryCreateDTO;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplateQuery;
use Thelia\Model\TemplateQuery;

#[Exclude]
final class CatalogLookup
{
    private array $known = [];
    private array $pending = [];
    private array $creations = [];

    public function __construct(
        private readonly CategoryFacade $categories,
        private readonly BrandFacade $brands,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $locale,
        private readonly bool $dryRun,
        private readonly bool $createMissing,
    ) {
    }

    public function category(string $path): Resolved
    {
        $current = new Resolved(0, false);
        $trail = [];

        foreach (CategoryPath::split($path) as $segment) {
            $trail[] = $segment;
            $parent = $current;
            $current = $this->resolve(
                'category',
                implode(' > ', $trail),
                implode(' > ', $trail),
                $parent->planned,
                fn (): ?int => CategoryQuery::create()
                    ->filterByParent((int) $parent->id)
                    ->useCategoryI18nQuery()->filterByLocale($this->locale)->filterByTitle($segment)->endUse()
                    ->findOne()?->getId(),
                fn (): int => $this->categories->create(new CategoryCreateDTO($segment, $this->locale, (int) $parent->id, true))->getId(),
            );
        }

        if (0 === $current->id && !$current->planned) {
            throw new Refusal(new ReportMessage('The category "%category" is empty', ['%category' => $path]));
        }

        return $current;
    }

    public function brand(string $name): Resolved
    {
        return $this->resolve(
            'brand',
            $name,
            $name,
            false,
            fn (): ?int => BrandQuery::create()
                ->useBrandI18nQuery()->filterByLocale($this->locale)->filterByTitle($name)->endUse()
                ->findOne()?->getId(),
            fn (): int => $this->brands->create(new BrandCreateDTO($name, $this->locale, true))->getId(),
        );
    }

    public function template(string $name): Resolved
    {
        return $this->resolve(
            'template',
            $name,
            $name,
            false,
            fn (): ?int => TemplateQuery::create()
                ->useTemplateI18nQuery()->filterByLocale($this->locale)->filterByName($name)->endUse()
                ->findOne()?->getId(),
            function () use ($name): int {
                $event = (new TemplateCreateEvent())->setLocale($this->locale)->setTemplateName($name);
                $this->dispatcher->dispatch($event, TheliaEvents::TEMPLATE_CREATE);

                return $event->getTemplate()->getId();
            },
        );
    }

    public function feature(string $name): Resolved
    {
        return $this->resolve(
            'feature',
            $name,
            $name,
            false,
            fn (): ?int => FeatureQuery::create()
                ->useFeatureI18nQuery()->filterByLocale($this->locale)->filterByTitle($name)->endUse()
                ->findOne()?->getId(),
            function () use ($name): int {
                $event = (new FeatureCreateEvent())->setLocale($this->locale)->setTitle($name)->setAddToAllTemplates(false);
                $this->dispatcher->dispatch($event, TheliaEvents::FEATURE_CREATE);

                return $event->getFeature()->getId();
            },
        );
    }

    public function featureValue(string $featureName, Resolved $feature, string $value): Resolved
    {
        return $this->resolve(
            'feature value',
            $featureName.' = '.$value,
            $featureName.' = '.$value,
            $feature->planned,
            fn (): ?int => FeatureAvQuery::create()
                ->filterByFeatureId((int) $feature->id)
                ->useFeatureAvI18nQuery()->filterByLocale($this->locale)->filterByTitle($value)->endUse()
                ->findOne()?->getId(),
            function () use ($feature, $value): int {
                $event = (new FeatureAvCreateEvent())->setLocale($this->locale)->setTitle($value)->setFeatureId($feature->id);
                $this->dispatcher->dispatch($event, TheliaEvents::FEATURE_AV_CREATE);

                return $event->getFeatureAv()->getId();
            },
        );
    }

    public function linkFeatureToTemplate(int $templateId, int $featureId): void
    {
        if (FeatureTemplateQuery::create()->filterByTemplateId($templateId)->filterByFeatureId($featureId)->count() > 0) {
            return;
        }

        $this->dispatcher->dispatch(new TemplateAddFeatureEvent(TemplateQuery::create()->findPk($templateId), $featureId), TheliaEvents::TEMPLATE_ADD_FEATURE);
    }

    public function commit(): array
    {
        $creations = $this->creations;
        $this->known = array_merge($this->known, $this->pending);
        $this->pending = [];
        $this->creations = [];

        return $creations;
    }

    public function rollback(): void
    {
        $this->pending = [];
        $this->creations = [];
    }

    private function resolve(string $kind, string $key, string $name, bool $parentPlanned, \Closure $find, \Closure $create): Resolved
    {
        $cacheKey = $kind.'|'.mb_strtolower($key);

        if (isset($this->pending[$cacheKey]) || isset($this->known[$cacheKey])) {
            return $this->pending[$cacheKey] ?? $this->known[$cacheKey];
        }

        $id = $parentPlanned ? null : $find();

        if (null !== $id) {
            return $this->pending[$cacheKey] = new Resolved($id, false);
        }

        if (!$this->createMissing) {
            throw new Refusal(new ReportMessage('The %kind "%name" does not exist', ['%kind' => $kind, '%name' => $name]));
        }

        $this->creations[] = new PlannedCreation($kind, $name);

        return $this->pending[$cacheKey] = $this->dryRun ? new Resolved(null, true) : new Resolved($create(), false);
    }
}
