<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Cache\EntityCacheTags;

/**
 * Front end content element "module".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ContentModule extends ContentElement
{
	/**
	 * Parse the template
	 *
	 * @return string
	 */
	public function generate()
	{
		if ($this->isHidden())
		{
			return '';
		}

		$objModel = ModuleModel::findByPk($this->module);

		if ($objModel === null)
		{
			return '';
		}

		$strClass = Module::findClass($objModel->type);

		if (!class_exists($strClass))
		{
			return '';
		}

		if (is_a($strClass, ContentProxy::class, true))
		{
			if (!empty($this->cssID[1]))
			{
				$objModel->classes = array_merge((array) $objModel->classes, array($this->cssID[1]));
			}

			/** @var ContentProxy $proxy */
			$proxy = new $strClass($objModel, $this->strColumn);

			if (!empty($this->cssID[0]))
			{
				$proxy->cssID = ' id="' . $this->cssID[0] . '"';
			}

			return $proxy->generate();
		}

		$cssID = StringUtil::deserialize($objModel->cssID, true);

		// Override the CSS ID (see #305)
		if (!empty($this->cssID[0]))
		{
			$cssID[0] = $this->cssID[0];
		}

		// Merge the CSS classes (see #6011)
		if (!empty($this->cssID[1]))
		{
			$cssID[1] = trim(($cssID[1] ?? '') . ' ' . $this->cssID[1]);
		}

		// Clone the model so we do not modify the shared model in the registry
		$objModel = $objModel->cloneOriginal();
		$objModel->cssID = $cssID;
		$objModel->typePrefix = 'ce_';

		$strStopWatchId = 'contao.frontend_module.' . $objModel->type . ' (ID ' . $objModel->id . ')';

		if (System::getContainer()->getParameter('kernel.debug'))
		{
			$objStopwatch = System::getContainer()->get('debug.stopwatch');
			$objStopwatch->start($strStopWatchId, 'contao.layout');
		}

		/** @var Module $objModule */
		$objModule = new $strClass($objModel, $this->strColumn);

		// Tag the content element (see #2137)
		System::getContainer()->get(EntityCacheTags::class)->tagWithModelInstance($this->objModel);

		$strBuffer = $objModule->generate();

		if (isset($objStopwatch) && $objStopwatch->isStarted($strStopWatchId))
		{
			$objStopwatch->stop($strStopWatchId);
		}

		return $strBuffer;
	}

	/**
	 * Generate the content element
	 */
	protected function compile()
	{
	}
}

class_alias(ContentModule::class, 'ContentModule');
