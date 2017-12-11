<?php

namespace DNADesign\Elemental\Models;

use Exception;
use DNADesign\Elemental\Forms\ElementalGridFieldHistoryButton;
use DNADesign\Elemental\Forms\HistoricalVersionedGridFieldItemRequest;
use DNADesign\Elemental\Forms\TextCheckboxGroupField;
use DNADesign\Elemental\Controllers\ElementController;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldPageCount;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldVersionedState;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\CMSPreviewable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Search\SearchContext;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;

class BaseElement extends DataObject implements CMSPreviewable
{
    /**
     * Override this on your custom elements to specify a CSS icon class
     *
     * @var string
     */
    private static $icon = 'font-icon-block-layout';

    /**
     * Describe the purpose of this element
     *
     * @config
     * @var string
     */
    private static $description = 'Base element class';

    private static $db = [
        'Title' => 'Varchar(255)',
        'ShowTitle' => 'Boolean',
        'InContainer' => 'Boolean',
        'Sort' => 'Int',
        'ExtraClass' => 'Varchar(255)',
        'Style' => 'Varchar(255)'
    ];

    private static $has_one = [
        'Parent' => ElementalArea::class
    ];

    private static $extensions = [
        Versioned::class
    ];

    private static $versioned_gridfield_extensions = true;

    private static $table_name = 'Element';

    /**
     * @var string
     */
    private static $controller_class = ElementController::class;

    /**
     * @var string
     */
    private static $controller_template = 'ElementHolder';

    /**
     * @var ElementController
     */
    protected $controller;

    private static $default_sort = 'Sort';

    private static $singular_name = 'block';

    private static $plural_name = 'blocks';

    private static $summary_fields = [
        'EditorPreview' => 'Summary'
    ];

    /**
     * @config
     * @var array
     */
    private static $styles = [];

    private static $searchable_fields = [
        'ID' => [
            'field' => NumericField::class,
        ],
        'Title',
        'LastEdited'
    ];

    /**
     * Enable for backwards compatibility
     *
     * @var boolean
     */
    private static $disable_pretty_anchor_name = false;

    /**
     * Store used anchor names, this is to avoid title clashes
     * when calling 'getAnchor'
     *
     * @var array
     */
    protected static $_used_anchors = [];

    /**
     * For caching 'getAnchor'
     *
     * @var string
     */
    protected $_anchor = null;

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canView($member = null)
    {
        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canView($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canEdit($member = null)
    {
        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canEdit($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * Uses archive not delete so that current stage is respected i.e if a
     * element is not published, then it can be deleted by someone who doesn't
     * have publishing permissions.
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canDelete($member = null)
    {
        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canArchive($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     * @param array $context
     *
     * @return boolean
     */
    public function canCreate($member = null, $context = array())
    {
        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if ($areaID = $this->ParentID) {
            if ($elementalArea = ElementalArea::get()->byID($areaID)) {
                $elementalArea->write();
            }
        }

        if (!$this->Sort) {
            $parentID = ($this->ParentID) ? $this->ParentID : 0;

            $this->Sort = static::get()->max('Sort') + 1;
        }
    }

    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            // Remove relationship fields
            $fields->removeByName('ParentID');
            $fields->removeByName('Sort');

            $fields->addFieldsToTab('Root.Settings', [
                TextField::create('ExtraClass', _t(__CLASS__ . '.ExtraCssClassesLabel', 'Custom CSS classes'))
                    ->setAttribute(
                        'placeholder',
                        _t(__CLASS__ . '.ExtraCssClassesPlaceholder', 'my_class another_class')
                    ),
                CheckboxField::create('InContainer', _t(__CLASS__ . '.InContainerLabel', 'Check this to center this block on layout'))
            ]);

            // Add a combined field for "Title" and "Displayed" checkbox in a Bootstrap input group
            $fields->removeByName('ShowTitle');
            $fields->replaceField(
                'Title',
                TextCheckboxGroupField::create(
                    TextField::create('Title', _t(__CLASS__ . '.TitleLabel', 'Title (displayed if checked)')),
                    CheckboxField::create('ShowTitle', _t(__CLASS__ . '.ShowTitleLabel', 'Displayed'))
                )
                    ->setName('TitleAndDisplayed')
            );

            // Rename the "Main" tab
            $fields->fieldByName('Root.Main')
                ->setTitle(_t(__CLASS__ . '.MainTabLabel', 'Content'));

            $fields->addFieldsToTab('Root.Main', [
                HiddenField::create('AbsoluteLink', false, Director::absoluteURL($this->PreviewLink())),
                HiddenField::create('LiveLink', false, Director::absoluteURL($this->Link())),
                HiddenField::create('StageLink', false, Director::absoluteURL($this->PreviewLink())),
            ]);

            $styles = $this->config()->get('styles');

            if ($styles && count($styles) > 0) {
                $styleDropdown = DropdownField::create('Style', _t(__CLASS__.'.STYLE', 'Style variation'), $styles);

                $fields->insertBefore($styleDropdown, 'ExtraClass');

                $styleDropdown->setEmptyString(_t(__CLASS__.'.CUSTOM_STYLES', 'Select a style..'));
            } else {
                $fields->removeByName('Style');
            }

            $history = $this->getHistoryFields();

            if ($history) {
                $fields->addFieldsToTab('Root.History', $history);
            }
        });

        return parent::getCMSFields();
    }

    /**
     * Returns the history fields for this element.
     *
     * @param  bool $checkLatestVersion Whether to check if this is the latest version. Prevents recursion, but can be
     *                                  overridden to get the history GridField if required.
     * @return FieldList
     */
    public function getHistoryFields($checkLatestVersion = true)
    {
        if ($checkLatestVersion && !$this->isLatestVersion()) {
            // if viewing the history of the of page then don't show the history
            // fields as then we have recursion.
            return null;
        }

        Requirements::javascript('dnadesign/silverstripe-elemental:client/dist/js/bundle.js');

        $config = GridFieldConfig_RecordViewer::create();
        $config->removeComponentsByType(GridFieldPageCount::class);
        $config->removeComponentsByType(GridFieldToolbarHeader::class);
        // Replace the sortable ID column with a static header component
        $config->removeComponentsByType(GridFieldSortableHeader::class);
        $config->addComponent(new GridFieldTitleHeader);

        $config
            ->getComponentByType(GridFieldDetailForm::class)
            ->setItemRequestClass(HistoricalVersionedGridFieldItemRequest::class);

        $config->getComponentByType(GridFieldDataColumns::class)
            ->setDisplayFields([
                'Version' => '#',
                'RecordStatus' => _t(__CLASS__ . '.Record', 'Record'),
                'getAuthor.Name' => _t(__CLASS__ . '.Author', 'Author')
            ])
            ->setFieldFormatting([
                'RecordStatus' => '$VersionedStateNice <span class=\"element-history__date--small\">on $LastEditedNice</span>',
            ]);

        $config->removeComponentsByType(GridFieldViewButton::class);
        $config->addComponent(new ElementalGridFieldHistoryButton());

        $history = Versioned::get_all_versions(__CLASS__, $this->ID)
            ->sort('Version', 'DESC');

        return FieldList::create(
            GridField::create('History', '', $history, $config)
                ->addExtraClass('elemental-block__history')
        );
    }

    /**
     * Get the type of the current block, for use in GridField summaries, block
     * type dropdowns etc. Examples are "Content", "File", "Media", etc.
     *
     * @return string
     */
    public function getType()
    {
        return _t(__CLASS__ . '.BlockType', 'Block');
    }

    /**
     * @param ElementController $controller
     *
     * @return $this
     */
    public function setController($controller)
    {
        $this->controller = $controller;

        return $this;
    }

    /**
     * @throws Exception If the specified controller class doesn't exist
     *
     * @return ElementController
     */
    public function getController()
    {
        if ($this->controller) {
            return $this->controller;
        }

        $controllerClass = self::config()->controller_class;

        if (!class_exists($controllerClass)) {
            throw new Exception('Could not find controller class ' . $controllerClass . ' as defined in ' . static::class);
        }

        $this->controller = Injector::inst()->create($controllerClass, $this);
        $this->controller->doInit();

        return $this->controller;
    }

    /**
     * @return Controller
     */
    public function Top()
    {
        return (Controller::has_curr()) ? Controller::curr() : null;
    }

    /**
     * Default way to render element in templates. Note that all blocks should
     * be rendered through their {@link ElementController} class as this
     * contains the holder styles.
     *
     * @return string|null HTML
     */
    public function forTemplate($holder = true)
    {
        $templates = $this->getRenderTemplates();

        if ($templates) {
            return $this->renderWith($templates);
        }
    }

    /**
     * @param string $suffix
     *
     * @return array
     */
    public function getRenderTemplates($suffix = '')
    {
        $classes = ClassInfo::ancestry($this->ClassName);
        $classes[static::class] = static::class;
        $classes = array_reverse($classes);
        $templates = array();

        foreach ($classes as $key => $value) {
            if ($value == BaseElement::class) {
                continue;
            }

            if ($value == DataObject::class) {
                break;
            }

            $templates[] = $value . $suffix;
        }

        return $templates;
    }

    /**
     * Strip all namespaces from class namespace.
     *
     * @param string $classname e.g. "\Fully\Namespaced\Class"
     *
     * @return string following the param example, "Class"
     */
    protected function stripNamespacing($classname)
    {
        $classParts = explode('\\', $classname);
        return array_pop($classParts);
    }

    /**
     * @return string
     */
    public function getSimpleClassName()
    {
        return strtolower($this->sanitiseClassName($this->ClassName, '__'));
    }

    /**
     * @return SiteTree
     */
    public function getPage()
    {
        $area = $this->Parent();

        if ($area instanceof ElementalArea && $area->exists()) {
            return $area->getOwnerPage();
        }

        return null;
    }

    /**
     * Get a unique anchor name
     *
     * @return string
     */
    public function getAnchor()
    {
        if ($this->_anchor !== null) {
            return $this->_anchor;
        }

        $anchorTitle = '';

        if (!$this->config()->disable_pretty_anchor_name) {
            if ($this->hasMethod('getAnchorTitle')) {
                $anchorTitle = $this->getAnchorTitle();
            } elseif ($this->config()->enable_title_in_template) {
                $anchorTitle = $this->getField('Title');
            }
        }

        if (!$anchorTitle) {
            $anchorTitle = 'e'.$this->ID;
        }

        $filter = URLSegmentFilter::create();
        $titleAsURL = $filter->filter($anchorTitle);

        // Ensure that this anchor name isn't already in use
        // ie. If two elemental blocks have the same title, it'll append '-2', '-3'
        $result = $titleAsURL;
        $count = 1;
        while (isset(self::$_used_anchors[$result]) && self::$_used_anchors[$result] !== $this->ID) {
            ++$count;
            $result = $titleAsURL.'-'.$count;
        }
        self::$_used_anchors[$result] = $this->ID;
        return $this->_anchor = $result;
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function AbsoluteLink($action = null)
    {
        if ($page = $this->getPage()) {
            $link = $page->AbsoluteLink($action) . '#' . $this->getAnchor();

            return $link;
        }
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function Link($action = null)
    {
        if ($page = $this->getPage()) {
            $link = $page->Link($action) . '#' . $this->getAnchor();

            $this->extend('updateLink', $link);

            return $link;
        }
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function PreviewLink($action = null)
    {
        $action = $action . '?ElementalPreview=' . mt_rand();
        $link = $this->Link($action);
        $this->extend('updatePreviewLink', $link);

        return $link;
    }

    /**
     * @return boolean
     */
    public function isCMSPreview()
    {
        if (Controller::has_curr()) {
            $controller = Controller::curr();

            if ($controller->getRequest()->requestVar('CMSPreview')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null
     */
    public function CMSEditLink()
    {
        $relationName = $this->getAreaRelationName();
        $page = $this->getPage(true);

        if (!$page) {
            return null;
        }

        $link = Controller::join_links(
            singleton(CMSPageEditController::class)->Link('EditForm'),
            $page->ID,
            'field/' . $relationName . '/item/',
            $this->ID
        );

        return Controller::join_links(
            $link,
            'edit'
        );
    }

    /**
     * Retrieve a elemental area relation for creating cms links
     *
     * @return string The name of a valid elemental area relation
     */
    public function getAreaRelationName()
    {
        $page = $this->getPage(true);

        if ($page) {
            $has_one = $page->config()->get('has_one');
            $area = $this->Parent();

            foreach ($has_one as $relationName => $relationClass) {
                if ($relationClass === $area->ClassName) {
                    return $relationName;
                }
            }
        }

        return 'ElementalArea';
    }

    /**
     * Sanitise a model class' name for inclusion in a link.
     *
     * @return string
     */
    public function sanitiseClassName($class, $delimiter = '-')
    {
        return str_replace('\\', $delimiter, $class);
    }

    public function unsanitiseClassName($class, $delimiter = '-')
    {
        return str_replace($delimiter, '\\', $class);
    }

    /**
     * @return string|null
     */
    public function getEditLink()
    {
        return $this->CMSEditLink();
    }

    /**
     * @return HTMLText
     */
    public function PageCMSEditLink()
    {
        if ($page = $this->getPage()) {
            return DBField::create_field('HTMLText', sprintf(
                '<a href="%s">%s</a>',
                $page->CMSEditLink(),
                $page->Title
            ));
        }
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return 'text/html';
    }

    /**
     * This can be overridden on child elements to create a summary for display
     * in GridFields.
     *
     * @return string
     */
    public function getSummary()
    {
        return '';
    }


    /**
     * Generate markup for element type icons suitable for use in GridFields.
     *
     * @return HTMLVarchar
     */
    public function getIcon()
    {
        $iconClass = $this->config()->get('icon');

        if ($iconClass) {
            return DBField::create_field('HTMLVarchar', '<i class="' . $iconClass . '"></i>');
        }
    }

    /**
     * Get a description for this content element, if available
     *
     * @return string
     */
    public function getDescription()
    {
        $description = $this->config()->uninherited('description');
        if ($description) {
            return _t(__CLASS__ . '.Description', $description);
        }
        return '';
    }

    /**
     * Generate markup for element type, with description suitable for use in
     * GridFields.
     *
     * @return DBField
     */
    public function getTypeNice()
    {
        $description = $this->getDescription();
        $desc = ($description) ? ' <span class="element__note"> &mdash; ' . $description . '</span>' : '';

        return DBField::create_field(
            'HTMLVarchar',
            $this->getType() . $desc
        );
    }

    /**
     * @return HTMLText
     */
    public function getEditorPreview()
    {
        $templates = $this->getRenderTemplates('_EditorPreview');
        $templates[] = BaseElement::class . '_EditorPreview';

        return $this->renderWith($templates);
    }

    /**
     * @return Member
     */
    public function getAuthor()
    {
        if ($this->AuthorID) {
            return Member::get()->byId($this->AuthorID);
        }
    }

    /**
     * Get a user defined style variant for this element, if available
     *
     * @return string
     */
    public function getStyleVariant()
    {
        $style = $this->Style;
        $styles = $this->config()->get('styles');

        if (isset($styles[$style])) {
            $style = strtolower($style);
        } else {
            $style = '';
        }

        $this->extend('updateStyleVariant', $style);

        return $style;
    }

    /**
     *
     */
    public function getPageTitle()
    {
        $page = $this->getPage();

        if ($page) {
            return $page->Title;
        }

        return null;
    }

    /**
     * Get a "nice" label for use in the block history GridField
     *
     * @return string
     */
    public function getVersionedStateNice()
    {
        if ($this->WasPublished) {
            return _t(__CLASS__ . '.Published', 'Published');
        }

        return _t(__CLASS__ . '.Modified', 'Modified');
    }

    /**
     * Return a formatted date for use in the block history GridField
     *
     * @return string
     */
    public function getLastEditedNice()
    {
        return $this->dbObject('LastEdited')->Nice();
    }
}
