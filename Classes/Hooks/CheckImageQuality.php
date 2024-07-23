<?php

namespace Libeo\LboImageQuality\Hooks;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CheckImageQuality
{
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldValues, DataHandler $dataHandler): void
    {

        $GLOBALS['LANG']->sL('LLL:EXT:lbo_image_quality/Resources/Private/Language/locallang.xlf:file.modified');

        if (isset($fieldValues['table_local']) && $fieldValues['table_local'] === 'sys_file') {
            $uidFile = $fieldValues['uid_local'];
            $factory = GeneralUtility::makeInstance(ResourceFactory::class);
            $fileObject = $factory->getFileObject($uidFile);

            if (!in_array($fileObject->getExtension(), ['gif', 'jpg', 'jpeg', 'png'])) {
                return;
            }

            $qualityReturn = $this->getQualityScore($fileObject->getContents(), $fileObject->getName());

            if (!$qualityReturn['return'] && $qualityReturn['message']) {
                $message = GeneralUtility::makeInstance(FlashMessage::class,
                    $qualityReturn['message'],
                    $GLOBALS['LANG']->sL('LLL:EXT:lbo_image_quality/Resources/Private/Language/locallang.xlf:message.error.validation'),
                    FlashMessage::ERROR,
                    true
                );

                $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
                $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
                $messageQueue->addMessage($message);
                return;
            }

            $score = $qualityReturn['score'];

            if ($score < 0.5) {
                $level = FlashMessage::ERROR;
            } elseif ($score > 0.5 && $score < 0.7) {
                $level = FlashMessage::WARNING;
            } else {
                $level = FlashMessage::OK;
            }

            $message = GeneralUtility::makeInstance(FlashMessage::class,
                $fileObject->getPublicUrl() . ' [Score ' . $score . ']',
                $GLOBALS['LANG']->sL('LLL:EXT:lbo_image_quality/Resources/Private/Language/locallang.xlf:message.ok.validation'),
                $level,
                true
            );

            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $messageQueue->addMessage($message);
        }
    }

    private function getQualityScore($content, $filename)
    {
        $configuration = $this->getConfiguration();

        if (!isset($configuration['api_user']) || !isset($configuration['api_secret'])) {
            return ['return' => false, 'message' => $GLOBALS['LANG']->sL('LLL:EXT:lbo_image_quality/Resources/Private/Language/locallang.xlf:message.api.keys')];
        }

        $params = array(
            'models' => 'quality',
            'api_user' => $configuration['api_user'],
            'api_secret' => $configuration['api_secret'],
        );
        $fields = [
            'media' => new \CURLStringFile($content, $filename)
        ];

        $ch = curl_init('https://api.sightengine.com/1.0/check.json?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        $response = curl_exec($ch);
        curl_close($ch);

        $output = json_decode($response, true);

        if ($output['status'] === 'success') {
            return ['return' => true, 'score' => $output['quality']['score']];
        }
        return ['return' => false, 'message' => $output['error']['message']];
    }

    private function getConfiguration()
    {
        /** @var ExtensionConfiguration $extensionConfiguration */
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        return $extensionConfiguration->get('lbo_image_quality');
    }
}
