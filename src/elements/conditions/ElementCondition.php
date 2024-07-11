<?php

namespace craft\elements\conditions;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\conditions\ConditionRuleInterface;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\errors\InvalidTypeException;
use craft\fields\conditions\FieldConditionRuleInterface;
use craft\models\FieldLayout;
use yii\base\InvalidConfigException;

/**
 * ElementCondition provides an element condition.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class ElementCondition extends BaseCondition implements ElementConditionInterface
{
    /**
     * @inerhitdoc
     */
    public bool $sortable = false;

    /**
     * @var string|null The element type being queried.
     * @phpstan-var class-string<ElementInterface>|null
     */
    public ?string $elementType = null;

    /**
     * @var string|null The selected element source key.
     * @since 4.1.0
     */
    public ?string $sourceKey = null;

    /**
     * @var string The field context that should be used when fetching custom fields’ condition rule types.
     * @see selectableConditionRules()
     */
    public string $fieldContext = 'global';

    /**
     * @var string[] The element query params that available rules shouldn’t compete with.
     */
    public array $queryParams = [];

    /**
     * @var ElementInterface|null The element that this condition is being executed in reference to, if any.
     *
     * @since 4.4.0
     */
    public ?ElementInterface $referenceElement = null;

    /**
     * @var FieldLayout[]
     * @see getFieldLayouts()
     * @see setFieldLayouts()
     */
    private array $_fieldLayouts;

    /**
     * Constructor.
     *
     * @param string|null $elementType
     * @phpstan-param class-string<ElementInterface>|null $elementType
     * @param array $config
     */
    public function __construct(?string $elementType = null, array $config = [])
    {
        $elementType = $elementType ?? $config['elementType'] ?? $config['attributes']['elementType'] ?? null;
        unset($config['elementType'], $config['attributes']['elementType']);

        if (
            $elementType !== null &&
            (!class_exists($elementType) || !is_subclass_of($elementType, ElementInterface::class))
        ) {
            throw new InvalidConfigException("Invalid element type: $elementType");
        }

        $this->elementType = $elementType;
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayouts(): array
    {
        if (isset($this->_fieldLayouts)) {
            return $this->_fieldLayouts;
        }

        if ($this->elementType === null) {
            return [];
        }

        // If we have a source key, we can fetch just the field layouts that are available to it
        if ($this->sourceKey) {
            return Craft::$app->getElementSources()->getFieldLayoutsForSource($this->elementType, $this->sourceKey);
        }

        return Craft::$app->getFields()->getLayoutsByType($this->elementType);
    }

    /**
     * @inheritdoc
     */
    public function setFieldLayouts(array $fieldLayouts): void
    {
        $fieldsService = Craft::$app->getFields();
        $this->_fieldLayouts = array_map(function(FieldLayout|array $fieldLayout) use ($fieldsService) {
            if (is_array($fieldLayout)) {
                $fieldLayout['type'] = $this->elementType;
                return $fieldsService->createLayout($fieldLayout);
            }
            return $fieldLayout;
        }, $fieldLayouts);
    }

    /**
     * @inheritdoc
     */
    protected function isConditionRuleSelectable(ConditionRuleInterface $rule): bool
    {
        if (!$rule instanceof ElementConditionRuleInterface) {
            return false;
        }

        if (!parent::isConditionRuleSelectable($rule)) {
            return false;
        }

        // Make sure the rule doesn't conflict with the existing params
        $queryParams = array_merge($this->queryParams);
        foreach ($this->getConditionRules() as $existingRule) {
            try {
                /** @var ElementConditionRuleInterface $existingRule */
                array_push($queryParams, ...$existingRule->getExclusiveQueryParams());
            } catch (InvalidConfigException) {
                return false;
            }
        }

        $queryParams = array_flip($queryParams);

        foreach ($rule->getExclusiveQueryParams() as $param) {
            if (isset($queryParams[$param])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function selectableConditionRules(): array
    {
        $types = [
            DateCreatedConditionRule::class,
            DateUpdatedConditionRule::class,
            IdConditionRule::class,
            RelatedToConditionRule::class,
            SlugConditionRule::class,
        ];

        /** @var string|ElementInterface|null $elementType */
        /** @phpstan-var class-string<ElementInterface>|ElementInterface|null $elementType */
        $elementType = $this->elementType;

        if (Craft::$app->getIsMultiSite() && (!$elementType || $elementType::isLocalized())) {
            $types[] = SiteConditionRule::class;
        }

        if ($elementType !== null) {
            if ($elementType::hasUris()) {
                $types[] = HasUrlConditionRule::class;
                $types[] = UriConditionRule::class;
            }

            if ($elementType::hasStatuses()) {
                $types[] = StatusConditionRule::class;
            }

            if ($elementType::hasTitles()) {
                $types[] = TitleConditionRule::class;
            }

            $fieldLabels = [];

            foreach ($this->getFieldLayouts() as $fieldLayout) {
                foreach ($fieldLayout->getCustomFieldElements() as $layoutElement) {
                    // Discard fields with empty/non-unique labels
                    $label = $layoutElement->label();
                    if ($label === null || isset($fieldLabels[$label])) {
                        continue;
                    }
                    $field = $layoutElement->getField();
                    $type = $field->getElementConditionRuleType();
                    if ($type === null) {
                        continue;
                    }

                    $fieldLabels[$label] = true;

                    if (is_string($type)) {
                        $type = ['class' => $type];
                    }
                    if (!is_subclass_of($type['class'], FieldConditionRuleInterface::class)) {
                        throw new InvalidTypeException($type['class'], FieldConditionRuleInterface::class);
                    }

                    $type['fieldUid'] = $field->uid;
                    $type['layoutElementUid'] = $field->layoutElement->uid;

                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['elementType', 'fieldLayouts', 'fieldContext'], 'safe'];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getBuilderConfig(): array
    {
        $config = parent::getBuilderConfig();
        if (isset($this->_fieldLayouts)) {
            $config['fieldLayouts'] = array_map(fn(FieldLayout $layout) => $layout->getConfig(), $this->_fieldLayouts);
        }
        return $config;
    }

    /**
     * @inheritdoc
     */
    protected function config(): array
    {
        return $this->toArray(['elementType', 'fieldContext']);
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        foreach ($this->getConditionRules() as $rule) {
            /** @var ElementConditionRuleInterface $rule */
            $rule->modifyQuery($query);
        }
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        foreach ($this->getConditionRules() as $rule) {
            /** @var ElementConditionRuleInterface $rule */
            if (!$rule->matchElement($element)) {
                return false;
            }
        }

        return true;
    }
}
