<?php
namespace gorriecoe\DataObjectHistory\Forms;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;

/**
 * DataObjectHistory
 *
 * @package silverstripe-dataobjecthistory
 */
class HistoryGridFieldItemRequest extends VersionedGridFieldItemRequest
{
    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'view' => true,
        'ItemEditForm' => true
    ];

    /**
     * @var int
     */
    protected $version;

    public function __construct($gridField, $component, $record, $requestHandler, $popupFormName)
    {
        try {
            $record = $this->getVersionRecordFromRecord($record, $requestHandler);
            parent::__construct($gridField, $component, $record, $requestHandler, $popupFormName);
        } catch (\Exception $e) {
            return $requestHandler->httpError(
                404,
                _t(__CLASS__ . '.InvalidVersion', $e->getMessage())
            );
        }
    }

    /**
     * Get the record at the requested version
     * @return DataObject
     */
    protected function getVersionRecordFromRecord(DataObject $record, $requestHandler) : DataObject {
        // validate version ID
        $this->version = $requestHandler->getRequest()->requestVar('v');
        if(!$this->version) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".VERSION_NOT_PROVIDED",
                    "No version provided"
                )
            );
        }

        // validate the record
        if(!$record->hasExtension(Versioned::class)) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".RECORD_NOT_VERSIONED",
                    "The record is not versioned"
                )
            );
        }

        if(!$record->canView()) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".NO_ACCESS",
                    "You do not have access to this record"
                )
            );
        }

        $versioned_record = $record->VersionsList()
                    ->filter('Version', $this->version)
                    ->first();
        if(empty($versioned_record->ID)) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".VERSION_NOT_FOUND",
                    "No version #{$this->version} found for this record"
                )
            );
        }

        return $version;
    }

    public function view($request)
    {
        if (!$this->record->canView()) {
            $this->httpError(403);
        }
        $controller = $this->getToplevelController();
        $form = $this->ItemEditForm();
        $data = ArrayData::create([
            'Backlink' => $controller->Link(),
            'ItemEditForm' => $form
        ]);
        $return = $data->renderWith($this->getTemplates());
        if ($request->isAjax()) {
            return $return;
        }
        return $controller->customise(['Content' => $return]);
    }

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();
        $fields = $form->Fields();
        $record = $this->record;
        $fields->push(
            HiddenField::create(
                'v',
                'Version',
                $record->Version
            )
        );

        // if the record has a 'Sort' field
        if($record->hasField('Sort')) {
            $fields->addFieldToTab(
                'Root.Main',
                ReadonlyField::create(
                    'Sort',
                    _t(__CLASS__ . '.Position', 'Position'),
                    $record->Sort
                )
            );
        }

        $fields = $fields->makeReadonly();

        if ($record->isLatestVersion()) {
            $message = _t(
                __CLASS__ . '.VIEWINGLATEST',
                "Currently viewing the latest version, created {created}",
                [
                    'version' => $this->version,
                    'created' => $record->Created
                ]
            );
        } else {
            $message = _t(
                __CLASS__ . '.VIEWINGVERSION',
                "Currently viewing version {version}, created {created}",
                [
                    'version' => $this->version,
                    'created' => $record->Created
                ]
            );
        }

        $form->sessionMessage(
            DBField::create_field('HTMLFragment', $message),
            'notice'
        );
        $form->setFields($fields);
        return $form;
    }

    public function doRollback($data, $form)
    {
        $record = $this->record;

        if ($record->isLatestVersion()) {
            return $this->httpError(
                    403,
                    _t(
                        __CLASS__ . ".CANNOT_ROLLBACK_LATEST_VERSION",
                        "You cannot roll back to this version, as it is the latest version"
                    )
            );
        }

        // Check permission
        if (!$record->canEdit()) {
            return $this->httpError(
                    403,
                    _t(
                        __CLASS__ . ".NO_ACCESS",
                        "Forbidden"
                    )
            );
        }

        // Save from form data
        $record->rollbackRecursive($record->Version);
        $link = '<a href="' . $this->Link('edit') . '">"'
            . htmlspecialchars($record->Title, ENT_QUOTES)
            . '"</a>';

        $message = _t(
            __CLASS__ . '.RolledBack',
            'Rolled back {name} to version {version} {link}',
            array(
                'name' => $record->i18n_singular_name(),
                'version' => $record->Version,
                'link' => $link
            )
        );

        $form->sessionMessage($message, 'good', ValidationResult::CAST_HTML);

        $controller = $this->getToplevelController();
        return $controller->redirect($record->CMSEditLink());
    }

    public function getFormActions()
    {
        $record = $this->getRecord();
        if (!$record || !$record->hasExtension(Versioned::class)) {
            return null;
        }

        // cannot show rollback for latest version, makes no sense
        if ($record->isLatestVersion()) {
            return null;
        }

        $actions = Fieldlist::create();
        if ($record->canEdit()) {
            $actions->push(
                FormAction::create(
                    'doRollback',
                    _t(__CLASS__ . '.REVERT', 'Revert to this version')
                )
                    ->setUseButtonTag(true)
                    ->setDescription(_t(
                        __CLASS__ . '.BUTTONREVERTDESC',
                        'Publish this record to the draft site'
                    ))
                    ->addExtraClass('btn-warning font-icon-back-in-time')
            );
        }
        return $actions;
    }
}
