<?php
namespace DERHANSEN\SfBanners\Controller;
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Torben Hansen <derhansen@gmail.com>, Skyfillers GmbH
 *  
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Cache\Cache;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;

/**
 * Banner Controller
 *
 * @package sf_banners
 */
class BannerController extends ActionController {

	/**
	 * Banner Service
	 *
	 * @var \DERHANSEN\SfBanners\Service\BannerService
	 * @inject
	 */
	protected $bannerService;

	/**
	 * bannerRepository
	 *
	 * @var \DERHANSEN\SfBanners\Domain\Repository\BannerRepository
	 * @inject
	 */
	protected $bannerRepository;

	/**
	 * Hash Service
	 *
	 * @var \TYPO3\CMS\Extbase\Security\Cryptography\HashService
	 * @inject
	 */
	protected $hashService;

	/**
	 * Instance of Caching Framework
	 *
	 * @var \TYPO3\CMS\Core\Cache\Frontend\AbstractFrontend
	 */
	protected $cacheInstance;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->initializeCache();
	}

	/**
	 * Initialize cache instance to be ready to use
	 *
	 * @return void
	 */
	protected function initializeCache() {
		Cache::initializeCachingFramework();
		try {
			$this->cacheInstance = $GLOBALS['typo3CacheManager']->getCache('sfbanners_cache');
		} catch (NoSuchCacheException $e) {
			$this->cacheInstance = $GLOBALS['typo3CacheFactory']->create(
				'sfbanners_cache',
				$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sfbanners_cache']['frontend'],
				$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sfbanners_cache']['backend'],
				$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sfbanners_cache']['options']
			);
		}
	}

	/**
	 * Click Action for a banner
	 *
	 * @param \DERHANSEN\SfBanners\Domain\Model\Banner $banner
	 * @return void
	 */
	public function clickAction(\DERHANSEN\SfBanners\Domain\Model\Banner $banner) {
		$banner->increaseClicks();
		
		// Check whether the banner has reached its click notification limit
		$clicksNotify = $banner->getClicksNotify();
		if($clicksNotify > 0) {
			if($banner->getClicks() >= $clicksNotify) {
				if($this->sendNotificationEmail($banner, 'clicks', $clicksNotify)) {
					$banner->setClicksNotify(0);
					$this->bannerRepository->update($banner);
				}
			}
		}
		
		
		$this->bannerRepository->update($banner);
		$this->redirectToURI($banner->getLinkUrl());
	}

	/**
	 * Show action
	 *
	 * @return void
	 */
	public function showAction() {
		$uniqueid = strtolower(substr(base64_encode(sha1(microtime())),0,9));
		$stringToHash = $GLOBALS['TSFE']->id . $this->settings['category'] . $this->settings['startingPoint'] .
			$this->settings['displayMode'];
		$hmac = $this->hashService->generateHmac($stringToHash);

		$this->view->assign('pid', $GLOBALS['TSFE']->id);
		$this->view->assign('lang', $GLOBALS['TSFE']->sys_language_uid);
		$this->view->assign('categories', $this->settings['category']);
		$this->view->assign('startingPoint', $this->settings['startingPoint']);
		$this->view->assign('displayMode', $this->settings['displayMode']);
		$this->view->assign('typeNum', $this->settings['ajaxPageTypeNum']);
		$this->view->assign('uniqueid', $uniqueid);
		$this->view->assign('hmac', $hmac);

		/* Find all banners and add additional CSS */
		$banners = $this->bannerRepository->findAll();
		$cssFile = $this->bannerService->getAdditionalCssFile($banners);

		if ($cssFile != '') {
			$GLOBALS['TSFE']->getPageRenderer()->addCssFile($cssFile, 'stylesheet', 'all', '', TRUE);
		}
	}

	/**
	 * Returns banners for the given parameters if given Hmac validation succeeds
	 *
	 * @param string $categories
	 * @param string $startingPoint
	 * @param string $displayMode
	 * @param int $currentPageUid
	 * @param string $hmac
	 * @return string
	 */
	public function getBannersAction($categories = '', $startingPoint = '', $displayMode = 'all', $currentPageUid = 0,
									$hmac = '') {
		$compareString = $currentPageUid . $categories . $startingPoint . $displayMode;

		if ($this->hashService->validateHmac($compareString, $hmac)) {
			/** @var \DERHANSEN\SfBanners\Domain\Model\BannerDemand $demand  */
			$demand = $this->objectManager->get('DERHANSEN\\SfBanners\\Domain\\Model\\BannerDemand');
			$demand->setCategories($categories);
			$demand->setStartingPoint($startingPoint);
			$demand->setDisplayMode($displayMode);
			$demand->setCurrentPageUid($currentPageUid);

			/* Get banners */
			$banners = $this->bannerRepository->findDemanded($demand);

			/* Update Impressions */
			$this->bannerRepository->updateImpressions($banners);
			
			foreach($banners as $banner) {
				// Check whether the banner has reached its impressions notification limit		
				$impressionsNotify = $banner->getImpressionsNotify();
				if($impressionsNotify > 0) {
					if($banner->getImpressions() >= $impressionsNotify) {
						if($this->sendNotificationEmail($banner, 'impressions', $impressionsNotify)) {
							$banner->setImpressionsNotify(0);
							$this->bannerRepository->update($banner);
						};
					}
				}
			}

			/* Collect identifier based on uids for all banners */
			$ident = $GLOBALS['TSFE']->id . $GLOBALS['TSFE']->sys_language_uid;
			foreach ($banners as $banner) {
				$ident .= $banner->getUid();
			}

			$ret = $GLOBALS['typo3CacheManager']->getCache('sfbanners_cache')->get(sha1($ident));
			if ($ret === FALSE || $ret === NULL) {
				$this->view->assign('banners', $banners);
				$this->view->assign('settings', $this->settings);
				$ret = $this->view->render();

				// Save value in cache
				$GLOBALS['typo3CacheManager']->getCache('sfbanners_cache')->set(sha1($ident), $ret, array('sf_banners'),
					$this->settings['cacheLifetime']);
			}
		} else {
			$ret = LocalizationUtility::translate('wrong_hmac', 'SfBanners');
		}
		return $ret;
	}
	
	
	
	/**
	* @param object $banner
	* @param int $limitName
	* @return boolean TRUE on success, otherwise false
	*/
	protected function sendNotificationEmail($banner, $limitName, $limit) {
		$templateName = 'NotificationEmail';
		
		/** @var \TYPO3\CMS\Fluid\View\StandaloneView $emailView */
		$emailView = $this->objectManager->get('TYPO3\\CMS\\Fluid\\View\\StandaloneView');

		$extbaseFrameworkConfiguration = $this->configurationManager->getConfiguration(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
		$templateRootPath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($extbaseFrameworkConfiguration['view']['templateRootPath']);
		$templatePathAndFilename = $templateRootPath . 'Email/' . $templateName . '.html';
		$emailView->setTemplatePathAndFilename($templatePathAndFilename);
		$emailView->assign('limitName', $limitName);
		$emailView->assign('limit', $limit);
		$emailView->assign('banner', $banner);
		$emailBody = $emailView->render();

		/** @var $message \TYPO3\CMS\Core\Mail\MailMessage */
		$message = $this->objectManager->get('TYPO3\\CMS\\Core\\Mail\\MailMessage');
		
		$sender = array($this->settings['notification']['sender']['email'] => $this->settings['notification']['sender']['name']);
		$recipient = array($this->settings['notification']['recipient']['email'] => $this->settings['notification']['recipient']['name']);
		
		$message
			->setSubject($this->settings['notification']['subject'])
			->setFrom($sender)
			->setTo($recipient)
		;

		// Plain text example
		$message->setBody($emailBody, 'text/plain');

		$message->send();
		return $message->isSent();
	}
	

}
