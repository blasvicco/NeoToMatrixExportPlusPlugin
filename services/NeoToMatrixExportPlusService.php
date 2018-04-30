<?php

namespace Craft;

/**
 * Class NeoToMatrixExportPlusService
 *
 * @package Craft
 */
class NeoToMatrixExportPlusService extends NeoService
{

  const RELATIONSHIP_TYPES = [ElementType::Entry, ElementType::Asset, ElementType::Category, ElementType::Tag, ElementType::User];

  /**
   * Converts a Neo block type model to a Matrix block type model.
   *
   * @param Neo_BlockTypeModel $neoBlockType
   * @param FieldModel|null $field
   * @return MatrixBlockTypeModel
   */
  public function convertBlockTypeToMatrix(Neo_BlockTypeModel $neoBlockType, FieldModel $field = null)
  {
    $matrixBlockType          = new MatrixBlockTypeModel();
    $matrixBlockType->fieldId = $field ? $field->id : $neoBlockType->fieldId;
    $matrixBlockType->name    = $neoBlockType->name;
    $matrixBlockType->handle  = $neoBlockType->handle;

    $neoFieldLayout = $neoBlockType->getFieldLayout();
    $neoFields      = $neoFieldLayout->getFields();
    $matrixFields   = [];

    // find all relabels for this block type to use when converting
    $relabels = [];
    if (craft()->plugins->getPlugin('relabel')) {
      foreach (craft()->relabel->getLabels($neoFieldLayout->id) as $relabel) {
        $relabels[$relabel->fieldId] = [
          'name'         => $relabel->name,
          'instructions' => $relabel->instructions,
        ];
      }
    }

    $ids = 1;
    foreach ($neoFields as $neoFieldLayoutField) {
      $neoField = $neoFieldLayoutField->getField();

      if (!in_array($neoField->type, ['Matrix', 'Neo'])) {
        $matrixField           = $neoField->copy();
        $matrixField->id       = 'new' . ($ids++);
        $matrixField->groupId  = null;
        $matrixField->required = (bool) $neoFieldLayoutField->required;

        // force disable translation on fields if the Neo field was also translatable
        if ($field && $field->translatable) {
          $matrixField->translatable = false;
        }

        // use the relabel name and instructions if they are set for this field
        if (array_key_exists($neoField->id, $relabels)) {
          foreach ($relabels[$neoField->id] as $property => $value) {
            if ($value) {
              $matrixField->$property = $value;
            }
          }
        }

        // fixing wrong index when copy field type PositionSelect
        if ($matrixField->type == 'PositionSelect') {
          $matrixFieldSettings            = $matrixField->getAttribute('settings');
          $matrixFieldSettings['options'] = array_combine($matrixField->settings['options'], $matrixField->settings['options']);
          $matrixField->setAttribute('settings', $matrixFieldSettings);
        }

        $matrixFields[] = $matrixField;
      } else if ($neoField->type == 'Matrix') {
        $innerFieldLayout = $neoFieldLayoutField->getLayout();
        $innerFields      = $innerFieldLayout->getFields();

        // new SuperTable field
        $stField          = $this->_newSuperTableField($neoField->name, $neoField->handle);

        // set block type
        $superTableBlockType = new SuperTable_BlockTypeModel();
        $superTableBlockType->setAttributes([
          'id'      => 'new',
          'fieldId' => $field->id,
        ]);

        $superTableFields = [];
        $this->_getBlockType($innerFields, $superTableFields);
        $superTableBlockType->setFields($superTableFields);

        // set settings
        $superTableSettings = new SuperTable_SettingsModel($stField);
        $superTableSettings->setBlockTypes([$superTableBlockType]);
        $superTableSettings->setAttribute('fieldLayout', 'row');

        // update field settings
        $stField->setAttribute('settings', $superTableSettings);
        $matrixFields[] = $stField;
      }
    }

    $matrixBlockType->setFields($matrixFields);

    return $matrixBlockType;
  }

  /**
   * Converts a Neo settings model to a Matrix settings model with Super Table fields for the nested blocks.
   *
   * @param Neo_SettingsModel $neoSettings
   * @param FieldModel|NULL $field
   * @param array $optimizerByKey (accumulative array)
   * @return MatrixSettingsModel
   */
  public function convertSettingsToMatrix(Neo_SettingsModel $neoSettings, FieldModel $field = null, array &$optimizerByKey)
  {
    $neoBlocks = [
      'topLevel'  => [],
      'byHandler' => [],
    ];

    $blocks                = $neoSettings->getBlockTypes();
    $blockParentsByHandler = $this->_calculateBlockTypeParents($blocks);

    // parsing blocks into top level and children by handler
    foreach ($blocks as $neoBlockType) {
      $neoBlocks['byHandler'][$neoBlockType->handle] = $neoBlockType;
      if (empty($blockParentsByHandler[$neoBlockType->handle]) || ($neoBlockType->topLevel == 1)) {
        $neoBlocks['topLevel'][] = $neoBlockType;
      }
    }

    $ids              = 1;
    $matrixBlockTypes = [];
    gc_disable();
    foreach ($neoBlocks['topLevel'] as $neoBlockType) {
      // generating main block
      $matrixBlockType     = $this->convertBlockTypeToMatrix($neoBlockType, $field);
      $matrixBlockType->id = 'new' . ($ids++);

      // generating the SuperTable fields per each children block of the main block
      $matrixFields = $matrixBlockType->getFields();
      if ($neoBlockType->childBlocks == '*') $neoBlockType->childBlocks = array_keys($neoBlocks['byHandler']);
      if (!empty($neoBlockType->childBlocks) && is_array($neoBlockType->childBlocks)) {
        foreach ($neoBlockType->childBlocks as $handle) {
          if ($handle == $neoBlockType->handle) continue;
          $this->_getAllLevelChildFields($neoBlocks['byHandler'][$handle], $neoBlocks['byHandler'], $handle, $neoBlockType->id, $matrixFields, $optimizerByKey);
        }
      }
      $matrixBlockType->setFields($matrixFields);

      // adding main block to the list
      $matrixBlockTypes[] = $matrixBlockType;
      gc_collect_cycles();
    }
    gc_enable();

    $matrixSettings = new MatrixSettingsModel($field);
    $matrixSettings->setBlockTypes($matrixBlockTypes);
    return $matrixSettings;
  }

  /**
   * Converts a Neo field into a Matrix one.
   * WARNING: Calling this will replace the Neo field with a Matrix one, so use with caution. Performing this
   * conversion cannot be undone.
   *
   * @param FieldModel $neoField
   * @return bool
   * @throws \Exception
   */
  public function convertFieldToMatrix(FieldModel $neoField, bool $clean)
  {
    $neoFieldType = $neoField->getFieldType();

    if ($neoFieldType instanceof NeoFieldType) {
      try
      {
        echo "Creating new matrix field...\n";
        $optimizerByKey = [];
        $neoSettings    = $neoFieldType->getSettings();
        $matrixSettings = $this->convertSettingsToMatrix($neoSettings, $neoField, $optimizerByKey);

        $matrixField = $neoField->copy();
        $neoFieldId  = $neoField->id;
        $neoField    = null;
        unset($neoField);

        $matrixField->setAttributes([
          'type'     => 'Matrix',
          'settings' => $matrixSettings,
        ]);
        if (!craft()->fields->saveField($matrixField, false)) {
          throw new \Exception("Unable to save Matrix field");
        }

        $matrixField = null;
        unset($matrixField);
        echo "New matrix field and setting saved.\n";

        echo "Starting migration data...\n";
        // save a mapping of block type handles to their Neo ID for use later on when migrating content
        $neoBlockTypes = $neoSettings->getBlockTypes();
        $neoSettings   = null;
        unset($neoSettings);
        $neoBlockTypeIds  = [];
        $neoBlockTypeHandle  = [];
        foreach ($neoBlockTypes as $neoBlockType) {
          $neoBlockTypeIds[$neoBlockType->handle] = $neoBlockType->id;
          $neoBlockTypeHandle[$neoBlockType->id] = $neoBlockType->handle;
        }
        $neoBlockTypes = null;
        unset($neoBlockTypes);

        // create a mapping of Neo block type ID's to Matrix block type ID's. This is used below to set the
        // correct block type to a converted Matrix block.
        $refArrays = $this->_iniRefArrays($neoBlockTypeIds, $matrixSettings->getBlockTypes());
        $matrixSettings   = null;
        unset($matrixSettings);

        $neoToMatrixBlockIds = [];
        $neoBlocks           = $this->_getBlocks($neoFieldId, array_keys($refArrays['neoToMatrixBlockTypeIds']), TRUE);
        gc_disable();
        while (!empty($neoBlocks)) {
          $neoBlock = array_shift($neoBlocks);
          $remaining = count($neoBlocks);
          echo "\rRemaining blocks: " . ($remaining < 100 ? ( $remaining < 10 ? '00' : '0') : '') . $remaining . " - Memory usage: " . floor(memory_get_usage() / 1048576) . ' MB';

          $matrixBlock         = $this->convertBlockToMatrix($neoBlock);
          $matrixBlock->typeId = $refArrays['neoToMatrixBlockTypeIds'][$neoBlock->typeId];

          $this->_setFieldsValues($neoBlock, $matrixBlock, $refArrays['availableFieldToBeDeleted']);

          $superTableFields     = [];
          $optimizeKeyParent    = $neoBlock->typeId;
          $childs               = $neoBlock->getDescendants(TRUE);
          foreach ($childs as $child) {
            $this->_getAllLevelChildFieldsValues($child, $refArrays['matrixFieldsByHandle'][$matrixBlock->typeId], $optimizerByKey, $optimizeKeyParent, $superTableFields);
          }

          // has this block already been saved before? (Happens when saving a block in multiple locales)
          $this->_checkMultipleLocales($neoBlock->id, $neoToMatrixBlockIds, $matrixBlock);
          if (!craft()->matrix->saveBlock($matrixBlock, false)) {
            throw new \Exception("Unable to save Matrix block");
          }
          // save the new Matrix block ID in case it has different content locales to save
          $neoToMatrixBlockIds[$neoBlock->id] = $matrixBlock->id;

          //save childs
          while (!empty($superTableFields)) {
            $block          = array_shift($superTableFields);
            $block->ownerId = $matrixBlock->id;

            // removing from available to be deleted
            $handle = $refArrays['matrixFieldsById'][$matrixBlock->typeId][$block->fieldId];
            $refArrays['availableFieldToBeDeleted'][$matrixBlock->typeId][$handle] = NULL;
            unset($refArrays['availableFieldToBeDeleted'][$matrixBlock->typeId][$handle]);

            if (!craft()->superTable->saveBlock($block, false)) {
              throw new \Exception("Unable to save SuperTable child block");
            }
          }

          $matrixBlock = null;
          unset($matrixBlock);

          gc_collect_cycles();
        }
        gc_enable();

        echo "\nData migrated.\n";
        echo "Erasing old data.\n";

        if ($clean) {
          $this->_removeNotNeededFields($refArrays['availableFieldToBeDeleted']);
        }
        $this->_removeAllNeoBlocks($neoFieldId);
        craft()->db->setActive(FALSE);
        return TRUE;
      } catch (\Exception $e) {
        NeoPlugin::log("Couldn't convert Neo field to Matrix: " . $e->getMessage(), LogLevel::Error);
        throw $e;
      }
    }
    return false;
  }

  /**
   * Calculate parents in block types array.
   *
   * @param array $blocks
   * @return array $blockParentsByHandler
   */
  private function _calculateBlockTypeParents($blocks)
  {
    $blockParentsByHandler = [];
    foreach ($blocks as $key => $block) {
      $handle                                = $block->handle;
      $blockParentsByHandler[$block->handle] = [];
      foreach ($blocks as $parentBlock) {
        if (is_array($parentBlock->childBlocks) && in_array($block->handle, $parentBlock->childBlocks)) {
          $blockParentsByHandler[$block->handle][] = $parentBlock->handle;
        }
      }
    }
    return $blockParentsByHandler;
  }

  /**
   * Check multiple locales
   *
   * @param int $neoBlockId
   * @param array $neoToMatrixBlockIds
   * @param MatrixBlockModel $matrixBlock
   */
  private function _checkMultipleLocales(int $neoBlockId, array $neoToMatrixBlockIds, MatrixBlockModel &$matrixBlock) {
    if (!empty($neoToMatrixBlockIds[$neoBlockId])) {
      $matrixBlockContent = $matrixBlock->getContent();

      // assign the new ID of the Matrix block as it has been saved before (for a different locale)
      $matrixBlock->id               = $neoToMatrixBlockIds[$neoBlockId];
      $matrixBlockContent->elementId = $neoToMatrixBlockIds[$neoBlockId];

      // saving the Matrix block for the first time causes it to copy it's content into all other
      // locales, meaning there should already exist a record for this block's content. In that case,
      // it's record ID needs to be retrieved so it can be updated correctly.
      $existingContent = craft()->content->getContent($matrixBlock);
      if($existingContent) {
        $matrixBlockContent->id = $existingContent->id;
      }
    } else {
      // saving this block for the first time, so make sure it doesn't have an id
      $matrixBlock->id = null;
    }
  }

  /**
   * Generate all the SuperTable field and its nested fields definition by recursion.
   *
   * @param Neo_BlockTypeModel $block
   * @param array $byHandleNeoBlocks
   * @param string $handle
   * @param string $parentKey
   * @param array $matrixFields (accumulative array)
   * @param array $optimizerByKey (accumulative array)
   */
  private function _getAllLevelChildFields(Neo_BlockTypeModel $block, array $byHandleNeoBlocks, string $handle, string $parentKey, array &$matrixFields, array &$optimizerByKey)
  {
    $key = $parentKey . '_' . $block->id;
    if (isset($optimizerByKey[$handle]) && in_array($key, $optimizerByKey[$handle])) return;

    $innerFieldLayout = $block->getFieldLayout();
    $innerFields      = $innerFieldLayout->getFields();

    if (!empty($innerFields)) {
      //new super table
      $stField              = $this->_newSuperTableField($block->name, $handle);

      // set block type
      $superTableBlockType = new SuperTable_BlockTypeModel();
      $superTableBlockType->setAttributes([
        'id'      => 'new',
        'fieldId' => $stField->id,
      ]);

      $superTableFields = [];
      $this->_getBlockType($innerFields, $superTableFields);
      $superTableBlockType->setFields($superTableFields);

      // set settings
      $superTableSettings = new SuperTable_SettingsModel($stField);
      $superTableSettings->setBlockTypes([$superTableBlockType]);
      $superTableSettings->setAttribute('fieldLayout', 'row');

      // update field settings
      $stField->setAttribute('settings', $superTableSettings);

      // adding Super Table field
      $isIn = false;
      foreach ($matrixFields as $_Field) {
        $isIn = $stField->handle == $_Field->handle;
        if ($isIn) break;
      }
      if (!$isIn) $matrixFields[] = $stField;
      if (empty($optimizerByKey[$handle])) $optimizerByKey[$handle] = [];
      $optimizerByKey[$handle][] = $key;
    }

    if (!empty($block->childBlocks) && is_array($block->childBlocks)) {
      // if it has children blocks
      foreach ($block->childBlocks as $innerHandle) {
        $this->_getAllLevelChildFields($byHandleNeoBlocks[$innerHandle], $byHandleNeoBlocks, $innerHandle, $key, $matrixFields, $optimizerByKey);
      }
    }
  }

  /**
   * Generate all the SuperTable blocks to save.
   *
   * @param BaseElementModel $block (Neo_BlockModel / MatrixBlockModel)
   * @param array $matrixFieldsByHandle
   * @param array $optimizerByKey
   * @param string $optimizeKeyParent
   * @param array $matrixFields (accumulative array)
   */
  private function _getAllLevelChildFieldsValues(BaseElementModel $block, array $matrixFieldsByHandle, array $optimizerByKey, string $optimizeKeyParent, array &$matrixFields)
  {
    $optimizeByKey = $optimizeKeyParent . '_' . $block->typeId;
    $handlers = array_keys($matrixFieldsByHandle);
    foreach ($handlers as $possibleHandler) {
      if ($matrixFieldsByHandle[$possibleHandler]->type != 'SuperTable') continue;

      foreach ($optimizerByKey[$possibleHandler] as $key) {
        if (strpos($key, $optimizeByKey) > -1) {
          $blockType    = $matrixFieldsByHandle[$possibleHandler]->settings->getBlockTypes()[0];
          $fieldsHandle = array_map(function($field) {return $field->handle;}, $blockType->getFields());

          $superTableBlocks = [];
          $this->_getBlockChildFieldsAndValues($block, $fieldsHandle, $superTableBlocks);
          foreach ($superTableBlocks as $populatedBlock) {
            // new super table block
            $stBlock = new SuperTable_BlockModel();
            $stBlock->setAttributes([
              'fieldId' => $matrixFieldsByHandle[$possibleHandler]->id,
              'typeId'  => $blockType->id,
            ]);
            $content = $stBlock->getContent()->setAttributes($populatedBlock);
            // adding Super Table field
            $matrixFields[] = $stBlock;
          }
        }
      }
    }
  }

  /**
   * Return all Neo blocks.
   *
   * @param int $fieldId
   * @param array $typeIds (defaul [])
   * @param bool $onlyParent (defaul FALSE)
   * @return array $neoBlocks
   */
  private function _getBlocks(int $fieldId, array $typeIds = [], bool $onlyParent = FALSE)
  {
    $neoBlocks = [];
    foreach (craft()->i18n->getSiteLocales() as $locale) {
      // get all locale content variations of each block
      foreach ($this->getBlocks($fieldId, null, null, $locale->id) as $neoBlock) {
        if (empty($typeIds) || in_array($neoBlock->typeId, $typeIds) && (!$onlyParent || ($onlyParent && empty($neoBlock->getParent())))) {
          $neoBlocks[] = $neoBlock;
        }
      }
      // make sure all owner localised blocks are retrieved as well
      foreach ($this->getBlocks($fieldId, null, $locale->id) as $neoBlock) {
        if (empty($typeIds) || in_array($neoBlock->typeId, $typeIds) && (!$onlyParent || ($onlyParent && empty($neoBlock->getParent())))) {
          $neoBlocks[] = $neoBlock;
        }
      }
    }
    return $neoBlocks;
  }

  /**
   * Get fields data to populate SuperTable Blocks by recursion.
   *
   * @param BaseElementModel $block (Neo_BlockModel / MatrixBlockModel)
   * @param array $fieldsHandle
   * @param array $superTableBlocks (accumulative array)
   * @param bool $checkChildren (defaul TRUE)
   */
  private function _getBlockChildFieldsAndValues(BaseElementModel $block, array $fieldsHandle, array &$superTableBlocks, $checkChildren = TRUE)
  {
    $innerFieldLayout = $block->getFieldLayout();
    $innerFields      = $innerFieldLayout->getFields();
    if (!empty($innerFields)) {
      $newBlock = [];
      foreach ($innerFields as $_FieldLayoutField) {
        $_Field      = $_FieldLayoutField->getField();
        $FieldValues = $block->getFieldValue($_Field->handle);
        $value       = $block->getContent()->getAttribute($_Field->handle);
        $elementType = method_exists($FieldValues, 'getElementType') ? $FieldValues->getElementType() : null;
        if (!empty($elementType)) {
          if (in_array($elementType->getClassHandle(), self::RELATIONSHIP_TYPES)) {
            $value = $FieldValues->ids();
          } else if ($elementType->getClassHandle() == ElementType::MatrixBlock) {
            foreach ($FieldValues as $innerBlock) {
              $this->_getBlockChildFieldsAndValues($innerBlock, $fieldsHandle, $superTableBlocks, false);
            }
            continue;
          }
        }

        if (in_array($_Field->handle, $fieldsHandle)) {
          $newBlock[$_Field->handle] = $value;
        }
      }

      if (!empty($newBlock)) {
        $superTableBlocks[] = $newBlock;
      }
    }

    if ($checkChildren) {
      foreach ($block->getDescendants(TRUE) as $child) {
        $this->_getBlockChildFieldsAndValues($child, $fieldsHandle, $superTableBlocks);
      }
    }
  }

  /**
   * Get all fields definition and its nested fields definition by recursion.
   *
   * @param array $innerFields
   * @param array $superTableFields (accumulative array)
   * @param array $processed (accumulative array)
   */
  private function _getBlockType(array $innerFields, &$superTableFields, array &$processed = [])
  {
    foreach ($innerFields as $layoutField) {
      $_Field = $layoutField->getField();
      if (!in_array($_Field->type, ['Matrix', 'Neo'])) {
        // if it is not matrix or neo copying field
        if (isset($processed[$_Field->handle])) continue;
        $_Field = $_Field->copy();
        $_Field->setAttributes([
          'id'           => 'new',
          'required'     => (bool) $layoutField->required,
          'translatable' => !($_Field && $_Field->translatable),
          'groupId'      => null,
        ]);
        // fixing wrong index when copy field type PositionSelect
        if ($_Field->type == 'PositionSelect') {
          $_FieldSettings            = $_Field->getAttribute('settings');
          $_FieldSettings['options'] = array_combine($_Field->settings['options'], $_Field->settings['options']);
          $_Field->setAttribute('settings', $_FieldSettings);
        }
        $superTableFields[] = $_Field;
        $processed[$_Field->handle] = TRUE;
      } else {
        // if it is a matrix or neo field get its inner field
        $_FieldSettings = $_Field->getFieldType()->getSettings();
        foreach ($_FieldSettings->getBlockTypes() as $blockType) {
          $superInnerFields = $blockType->getFieldLayout()->getFields();
          $this->_getBlockType($superInnerFields, $superTableFields, $processed);
        }
      }
    }
  }

  /**
   * Initialize array of references to fields
   *
   * @param array $neoBlockTypeIds
   * @param array $blocksType
   */
  private function _iniRefArrays(array $neoBlockTypeIds, array $blocksType)
  {
    $refArray = [
      'neoToMatrixBlockTypeIds' => [],
      'matrixFieldsByHandle'    => [],
      'matrixFieldsById'        => [],
      'availableFieldToBeDeleted' => [],
    ];
    foreach ($blocksType as $blockType) {
      // used to map neo to matrix
      $refArray['neoToMatrixBlockTypeIds'][$neoBlockTypeIds[$blockType->handle]] = $blockType->id;

      $_Fields = $blockType->getFieldLayout()->getFields();
      foreach ($_Fields as $layoutField) {
        $_Field = $layoutField->getField();

        // used to reference fields by id / handle
        $refArray['matrixFieldsByHandle'][$blockType->id][$_Field->handle] = $_Field;
        $refArray['matrixFieldsById'][$blockType->id][$_Field->id]         = $_Field->handle;

        // used to store the fields that are not been used
        $refArray['availableFieldToBeDeleted'][$blockType->id][$_Field->handle] = TRUE;
      }
    }
    return $refArray;
  }

  /**
   * Create an empty SuperTable field.
   *
   * @param string $name
   * @param string $name
   * @return FieldModel $field
   */
  private function _newSuperTableField($name, $handle)
  {
    $field    = new FieldModel();
    $settings = new SuperTable_SettingsModel();
    $settings->setAttribute('fieldLayout', 'table');
    $field->setAttributes([
      'id'       => 'new1',
      'name'     => $name,
      'handle'   => $handle,
      'type'     => 'SuperTable',
      'settings' => $settings,
    ]);
    return $field;
  }

  /**
   * Remove all Neo blocks.
   *
   * @param int $fieldId
   */
  private function _removeAllNeoBlocks(int $fieldId)
  {
    $blockIds = array_map(function ($block) {
      return $block->id;
    }, $this->_getBlocks($fieldId));
    craft()->neo->deleteBlockById($blockIds);
  }

  /**
   * Remove unused fields and SuperTable content tables
   *
   * @param array $availableFieldToBeDeleted
   */
  private function _removeNotNeededFields(array $availableFieldToBeDeleted)
  {
    foreach ($availableFieldToBeDeleted as $blockTypeId => $value) {
      $handlers = array_keys($value);
      foreach ($handlers as $handle) {
        craft()->db->createCommand()->delete(
          'fields',
          [
            'handle'  => $handle,
            'context' => 'matrixBlockType:'.$blockTypeId,
          ]
        );
        craft()->db->createCommand()->dropTableIfExists('supertablecontent_' . $blockTypeId . '_' . $handle);
        //removing block if has not fields
        $count = craft()->db->createCommand()
          ->from('fieldlayoutfields FLY')
          ->join('matrixblocktypes MBT', 'FLY.layoutId = MBT.fieldLayoutId')
          ->where('MBT.id = :matrixBlockTypeId', [':matrixBlockTypeId' => $blockTypeId])
          ->count('FLY.id');
        if ($count == 0) {
          craft()->matrix->deleteBlockType(craft()->matrix->getBlockTypeById($blockTypeId));
        }
      }
    }
  }

  /**
   * Set relational fields and calculating the available fields to be deleted.
   *
   * @param Neo_BlockModel $neoBlock
   * @param MatrixBlockModel $matrixBlock
   * @param array $availableFieldToBeDeleted
   */
  private function _setFieldsValues(Neo_BlockModel $neoBlock, MatrixBlockModel &$matrixBlock, array &$availableFieldToBeDeleted)
  {
    // set relational fields values
    $innerFieldLayout = $neoBlock->getFieldLayout();
    $innerFields      = $innerFieldLayout->getFields();
    while (!empty($innerFields)) {
      $_FieldLayoutField = array_shift($innerFields);
      $_Field      = $_FieldLayoutField->getField();
      $FieldValues = $neoBlock->getFieldValue($_Field->handle);
      $value       = $neoBlock->getContent()->getAttribute($_Field->handle);
      $elementType = method_exists($FieldValues, 'getElementType') ? $FieldValues->getElementType() : null;
      // we need to set only relational field values because the other ones were set in the convertBlockToMatrix function
      if (!empty($elementType)) {
        if (in_array($elementType->getClassHandle(), self::RELATIONSHIP_TYPES)) {
          $values = $FieldValues->ids();
          $matrixBlock->getContent()->setAttribute($_Field->handle, $values);
        }
      }

      if ($FieldValues instanceof ElementCriteriaModel) {
        $value = ($FieldValues->count() > 0) ? 'has value' : NULL;
      }

      // removing from available to be deleted
      if (!empty($value)) {
        $availableFieldToBeDeleted[$matrixBlock->typeId][$_Field->handle] = null;
        unset($availableFieldToBeDeleted[$matrixBlock->typeId][$_Field->handle]);
      }
    }
  }

}
