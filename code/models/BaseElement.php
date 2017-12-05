<?php

/**
 * @package elemental
 */
class BaseElement extends Widget
{
    /**
     * @var array $db
     */
    private static $db = array(
        'ExtraClass' => 'Varchar(255)',
        'HideTitle' => 'Boolean',
        'AvailableGlobally' => 'Boolean(1)',
        'InContainer' => 'Boolean(1)'
    );

    /**
     * @var array $has_one
     */
    private static $has_one = array(
        'List' => 'ElementList' // optional.
    );

    /**
     * @var array $has_many
     */
    private static $has_many = array(
        'VirtualClones' => 'ElementVirtualLinked'
    );

    /**
     * @var string
     */
    private static $title = "Content Block";

    /**
     * @var string
     */
    private static $singular_name = 'Content Block';

    /**
     * @var array
     */
    private static $summary_fields = array(
        'ID' => 'ID',
        'Title' => 'Title',
        'ElementType' => 'Type'
    );

    /**
     * @var array
     */
    private static $searchable_fields = array(
        'ID' => array(
            'field' => 'NumericField'
        ),
        'Title',
        'LastEdited',
        'ClassName',
        'AvailableGlobally'
    );

    /**
     * @var boolean
     */
    private static $enable_title_in_template = false;

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
    protected static $_used_anchors = array();

    /**
     * For caching 'getAnchor'
     *
     * @var string
     */
    protected $_anchor = null;

    /**
     * @var Object
     * The virtual owner VirtualLinkedElement
     */
    public $virtualOwner;

    /**
     * @config
     * Elements available globally by default
     */
     private static $default_global_elements = true;

     public function populateDefaults() {
        $this->AvailableGlobally = $this->config()->get('default_global_elements');
        parent::populateDefaults();
     }

    public function getCMSFields()
    {
        $fields = $this->scaffoldFormFields(array(
            'includeRelations' => ($this->ID > 0),
            'tabbed' => true,
            'ajaxSafe' => true
        ));

        $fields->insertAfter(new ReadonlyField('ClassNameTranslated', _t('BaseElement.TYPE', 'Type'), $this->i18n_singular_name()), 'Title');
        $fields->removeByName('ListID');
        $fields->removeByName('ParentID');
        $fields->removeByName('Sort');
        $fields->removeByName('ExtraClass');
        $fields->removeByName('AvailableGlobally');
        $fields->removeByName('InContainer');

        if (!$this->config()->enable_title_in_template) {
            $fields->removeByName('HideTitle');
            $title = $fields->fieldByName('Root.Main.Title');

            if ($title) {
                $title->setRightTitle('For reference only. Does not appear in the template.');
            }
        }

        $fields->addFieldToTab('Root.Settings', new TextField('ExtraClass', 'Extra CSS Classes to add'));
        $fields->addFieldToTab('Root.Settings', new CheckboxField('AvailableGlobally', 'Available globally - can be linked to multiple pages'));
        $fields->addFieldToTab('Root.Settings', new CheckboxField('InContainer', 'Center this block in layout'));

        if (!is_a($this, 'ElementList')) {
            $lists = ElementList::get()->filter('ParentID', $this->ParentID);

            if ($lists->exists()) {
                $fields->addFieldToTab('Root.Settings',
                    $move = new DropdownField('MoveToListID', 'Move this to another list', $lists->map('ID', 'CMSTitle'), '')
                );

                $move->setEmptyString('Select a list..');
                $move->setHasEmptyDefault(true);
            }
        }


        if($virtual = $fields->dataFieldByName('VirtualClones')) {
            if($this->Parent() && $this->Parent()->exists() && $this->Parent()->getOwnerPage() && $this->Parent()->getOwnerPage()->exists()) {
                $tab = $fields->findOrMakeTab('Root.VirtualClones');
                $tab->setTitle(_t('BaseElement.VIRTUALTABTITLE', 'Linked To'));

                $ownerPage = $this->Parent()->getOwnerPage();
                $tab->push(new LiteralField('DisplaysOnPage', sprintf(
                    "<p>The original content block appears on <a href='%s'>%s</a></p>",
                    ($ownerPage->hasMethod('CMSEditLink') && $ownerPage->canEdit()) ? $ownerPage->CMSEditLink() : $ownerPage->Link(),
                    $ownerPage->MenuTitle
                )));

                $virtual->setConfig(new GridFieldConfig_Base());
                $virtual
                    ->setTitle(_t('BaseElement.OTHERPAGES', 'Other pages'))
                    ->getConfig()
                        ->removeComponentsByType('GridFieldAddExistingAutocompleter')
                        ->removeComponentsByType('GridFieldAddNewButton')
                        ->removeComponentsByType('GridFieldDeleteAction')
                        ->removeComponentsByType('GridFieldDetailForm')
                        ->addComponent(new ElementalGridFieldDeleteAction());

                $virtual->getConfig()
                    ->getComponentByType('GridFieldDataColumns')
                    ->setDisplayFields(array(
                        'getPage.Title' => 'Title',
                        'getPage.Link' => 'Link'
                    ));
            }
        }

        $this->extend('updateCMSFields', $fields);

        if ($this->IsInDB()) {
            if ($this->isEndofLine('BaseElement') && $this->hasExtension('VersionViewerDataObject')) {
                $fields = $this->addVersionViewer($fields, $this);
            }
        }

        return $fields;
    }

    /**
     * Version viewer must only be added at if this is the final getCMSFields for a class.
     * in order to avoid having to rename all fields from eg Root.Main to Root.Current.Main
     * To do this we test if getCMSFields is from the current class
     */
    public function isEndofLine($className)
    {
        $methodFromClass = ClassInfo::has_method_from(
            $this->ClassName, 'getCMSFields', $className
        );

        if($methodFromClass) {
            return true;
        }
    }


    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->Sort) {
            $parentID = ($this->ParentID) ? $this->ParentID : 0;

            $this->Sort = DB::query("SELECT MAX(\"Sort\") + 1 FROM \"Widget\" WHERE \"ParentID\" = $parentID")->value();
        }

        if ($this->MoveToListID) {
            $this->ListID = $this->MoveToListID;
        }
    }

    /**
     * Ensure that if there are elements that are virtualised from this element
     * that we move the original element to replace one of the virtual elements
     * But only if it's a delete not an unpublish
     */
    public function onBeforeDelete() {
        parent::onBeforeDelete();

        if(Versioned::get_reading_mode() == 'Stage.Stage') {
            $firstVirtual = false;
            $allVirtual = $this->getVirtualLinkedElements();
            if ($this->getPublishedVirtualLinkedElements()->Count() > 0) {
                // choose the first one
                $firstVirtual = $this->getPublishedVirtualLinkedElements()->First();
                $wasPublished = true;
            } else if ($allVirtual->Count() > 0) {
                // choose the first one
                $firstVirtual = $this->getVirtualLinkedElements()->First();
                $wasPublished = false;
            }
            if ($firstVirtual) {
                $origParentID = $this->ParentID;
                $origSort = $this->Sort;

                $clone = $this->duplicate(false);

                // set clones values to first virtual's values
                $clone->ParentID = $firstVirtual->ParentID;
                $clone->Sort = $firstVirtual->Sort;

                $clone->write();
                if ($wasPublished) {
                    $clone->doPublish();
                    $firstVirtual->doUnpublish();
                }

                // clone has a new ID, so need to repoint
                // all the other virtual elements
                foreach($allVirtual as $virtual) {
                    if ($virtual->ID == $firstVirtual->ID) {
                        continue;
                    }
                    $pub = false;
                    if ($virtual->isPublished()) {
                        $pub = true;
                    }
                    $virtual->LinkedElementID = $clone->ID;
                    $virtual->write();
                    if ($pub) {
                        $virtual->doPublish();
                    }
                }

                $firstVirtual->delete();
            }
        }
    }

    /**
     * @return string
     */
    public function i18n_singular_name()
    {
        return _t(__CLASS__, $this->config()->title);
    }

    /**
     * @return string
     */
    public function getElementType()
    {
        return $this->i18n_singular_name();
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        if ($title = $this->getField('Title')) {
            return $title;
        } else {
            if (!$this->isInDb()) {
                return;
            }

            return $this->config()->title;
        }
    }

    /**
     * Get a unique anchor name
     *
     * @return string
     */
    public function getAnchor() {
        if ($this->_anchor !== null) {
            return $this->_anchor;
        }

        $anchorTitle = '';
        if (!$this->config()->disable_pretty_anchor_name) {
            if ($this->hasMethod('getAnchorTitle')) {
                $anchorTitle = $this->getAnchorTitle();
            } else if ($this->config()->enable_title_in_template) {
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
     * @return string
     */
    public function getCMSTitle()
    {
        if ($title = $this->getField('Title')) {
            return $this->config()->title . ': ' . $title;
        } else {
            if (!$this->isInDb()) {
                return;
            }
            return $this->config()->title;
        }
    }

    public function ControllerTop()
    {
        return Controller::curr();
    }

    public function getPage()
    {
        if ($this->virtualOwner) {
            return $this->virtualOwner->getPage();
        }

        $area = $this->Parent();

        if ($area instanceof ElementalArea) {
            return $area->getOwnerPage();
        }

        return null;
    }

    /**
     * Override the {@link Widget::forTemplate()} method so that holders are not rendered twice. The controller should
     * render with widget inside the
     *
     * @return HTML
     */
    public function forTemplate($holder = true)
    {
        $config = SiteConfig::current_site_config();

        if ($config->Theme) Config::inst()->update('SSViewer', 'theme', $config->Theme);

        return $this->renderWith($this->class);
    }

    /**
     * @return string
     */
    public function getEditLink() {
        return Controller::join_links(
            Director::absoluteBaseURL(),
            'admin/elemental/BaseElement/EditForm/field/BaseElement/item',
            $this->ID,
            'edit'
        );
    }

    public function onBeforeVersionedPublish()
    {

    }

		public static function all_allowed_elements() {
        $classes = array();

        // get all dataobject with the elemental extension
        foreach(ClassInfo::subclassesFor('DataObject') as $className) {
            if(Object::has_extension($className, 'ElementPageExtension')) {
               $classes[] = $className;
            }
        }

        // get all allowd_elements for these classes
        $allowed = array();
        foreach($classes as $className) {
            $allowed_elements = Config::inst()->get($className, 'allowed_elements');
            if ($allowed_elements) {
                $allowed = array_merge($allowed, $allowed_elements);
            }
        }

       // $allowed[] = 'ElementVirtualLinked';
        $allowed = array_unique($allowed);

        $elements = array();
        foreach($allowed as $className) {
            $elements[$className] = _t($className, Config::inst()->get($className, 'title'));
        }

        asort($elements);

        return $elements;
    }

    public function getDefaultSearchContext()
    {
        $fields = $this->scaffoldSearchFields();

        $elements = BaseElement::all_allowed_elements();

        $fields->push(DropdownField::create('ClassName', 'Element Type', $elements)
            ->setEmptyString('All types'));
        $filters = $this->owner->defaultSearchFilters();

        return new SearchContext(
            $this->owner->class,
            $fields,
            $filters
        );
    }

    public function setVirtualOwner(ElementVirtualLinked $virtualOwner) {
        $this->virtualOwner = $virtualOwner;
    }

    /**
     * Finds and returns elements
     * that are virtual elements which link to this element
     */
    public function getVirtualLinkedElements() {
        return ElementVirtualLinked::get()->filter('LinkedElementID', $this->ID);
    }

    /**
     * Finds and returns published elements
     * that are virtual elements which link to this element
     */
    public function getPublishedVirtualLinkedElements() {
        $current = Versioned::get_reading_mode();
        Versioned::set_reading_mode('Stage.Live');
        $v = $this->getVirtualLinkedElements();
        Versioned::set_reading_mode($current);
        return $v;
    }
}
