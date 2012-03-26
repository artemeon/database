<?php
/*"******************************************************************************************************
*   (c) 2007-2012 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
*-------------------------------------------------------------------------------------------------------*
*	$Id: class_module_system_aspect.php 4529 2012-03-07 12:54:19Z sidler $                          *
********************************************************************************************************/

/**
 * The messagehandler provides common methods to interact with the messaging-subsystem
 *
 * @author sidler@mulchprod.de
 * @since 4.0
 * @package module_messaging
 */
class class_module_messaging_messagehandler {

    /**
     * @return interface_messageprovider[]
     */
    public function getMessageproviders() {
        $arrFiles = class_resourceloader::getInstance()->getFolderContent("/system/messageproviders", array(".php"));
        $arrReturn = array();

        foreach($arrFiles as $strOneFile) {
            if(uniStrpos($strOneFile, "interface") === false) {
                $strName = uniSubstr($strOneFile, 0, -4);
                $arrReturn[] = new $strName();
            }
        }


        return $arrReturn;
    }


    /**
     * Sends a message.
     * If the list of recipients containes a group, the message is duplicated for each member.
     *
     * @param string $strContent
     * @param class_module_user_group[]|class_module_user_user[] $arrRecipients
     * @param interface_messageprovider $objProvider
     * @return bool
     */
    public function sendMessage($strContent, $arrRecipients, interface_messageprovider $objProvider) {
        $objValidator = new class_email_validator();
        $strInternalIdentifier = generateSystemid();

        if($arrRecipients instanceof class_module_user_group || $arrRecipients instanceof class_module_user_user)
            $arrRecipients = array($arrRecipients);

        $arrRecipients = $this->getRecipientsFromArray($arrRecipients);

        foreach($arrRecipients as $objOneUser) {

            $objConfig = class_module_messaging_config::getConfigForUserAndProvider($objOneUser->getSystemid(), $objProvider);

            if($objConfig->getBitEnabled()) {
                $objMessage = new class_module_messaging_message();
                $objMessage->setStrBody($strContent);
                $objMessage->setStrUser($objOneUser->getSystemid());
                $objMessage->setStrInternalIdentifier($strInternalIdentifier);
                $objMessage->setStrMessageProvider(get_class($objProvider));

                $objMessage->updateObjectToDb();

                if($objConfig->getBitBymail() && $objValidator->validate($objOneUser->getStrEmail()))
                    $this->sendMessageByMail($objMessage, $objOneUser);
            }
        }

    }

    /**
     * Sends a copy of the message to the user by mail
     *
     * @param class_module_messaging_message $objMessage
     * @param class_module_user_user $objUser
     * @return bool
     */
    private function sendMessageByMail(class_module_messaging_message $objMessage, class_module_user_user $objUser) {

        $strSubject = class_carrier::getInstance()->getObjLang()->getLang("message_subject", "messaging");
        $strBody = class_carrier::getInstance()->getObjLang()->getLang("message_prolog", "messaging");

        $strBody .= "\n\n".getLinkAdminHref("messaging", "view", "&systemid=".$objMessage->getSystemid(), false)."\n\n";
        $strBody .= $objMessage->getStrBody();

        $objMail = new class_mail();
        $objMail->setSubject($strSubject);
        $objMail->setText($strBody);
        $objMail->addTo($objUser->getStrEmail());

        return $objMail->sendMail();
    }

    /**
     * Transforms a mixed array of users and groups into a list of users.
     *
     * @param class_module_user_group[]|class_module_user_user[] $arrRecipients
     * @return class_module_user_user[]
     */
    private function getRecipientsFromArray($arrRecipients) {
        $arrReturn = array();

        foreach($arrRecipients as $objOneRecipient) {
            if($objOneRecipient instanceof class_module_user_user) {
                $arrReturn[$objOneRecipient->getStrSystemid()] = $objOneRecipient;
            }
            else if($objOneRecipient instanceof class_module_user_group) {
                $objUsersources = new class_module_user_sourcefactory();
                $arrMembers = $objUsersources->getSourceGroup($objOneRecipient)->getUserIdsForGroup();

                foreach($arrMembers as $strOneId) {
                    if(!isset($arrReturn[$strOneId]))
                        $arrReturn[$strOneId] = new class_module_user_user($strOneId);
                }
            }
        }


        return $arrReturn;
    }
}