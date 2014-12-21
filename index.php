<?php
/**************************************************************************
**********      English Wikipedia Account Request Interface      **********
***************************************************************************
** Wikipedia Account Request Graphic Design by Charles Melbye,           **
** which is licensed under a Creative Commons                            **
** Attribution-Noncommercial-Share Alike 3.0 United States License.      **
**                                                                       **
** All other code are released under the Public Domain                   **
** by the ACC Development Team.                                          **
**                                                                       **
** See CREDITS for the list of developers.                               **
***************************************************************************/

// Get all the classes.
require_once 'config.inc.php';
require_once 'functions.php';

require_once 'includes/PdoDatabase.php';
require_once 'includes/SmartyInit.php';

// Check to see if the database is unavailable.
// Uses the true variable as the public uses this page.
if(Offline::isOffline())
{
    echo Offline::getOfflineMessage(true);
    die();
}

// TODO: move me to a maintenance job
if ($enableEmailConfirm == 1) 
{
    Request::cleanExpiredUnconfirmedRequests();
}

$antispoofProvider = new $antispoofProviderClass();
$xffTrustProvider  = new $xffTrustProviderClass($squidIpList);
$database          = gGetDb();

// Display the header of the interface.
BootstrapSkin::displayPublicHeader();

if(isset($_GET['action']) && $_GET['action'] == "confirm")
{
    try
    {
        if(!isset($_GET['id']) || !isset($_GET['si']))
        {
            BootstrapSkin::displayAlertBox("Please check the link you received", "alert-error", "Missing parameters", true, false);
            BootstrapSkin::displayPublicFooter();  
            die();
        }
        
        $request = Request::getById($_GET['id'], $database);
        
        if($request === false)
        {
            BootstrapSkin::displayAlertBox("We can't find your request in our system. Please note that requests expire after a certain period, so if it's been a little while, please submit again.", "alert-error", "Request not found", true, false);
            BootstrapSkin::displayPublicFooter();  
            die();
        }
        
        if($request->getEmailConfirm() == "Confirmed")
        {
            $smarty->display("request/email-confirmed.tpl");
            BootstrapSkin::displayPublicFooter();
            return;
        }
        
        $database->transactionally(function() use($database, $request) 
        {        
            if($request === false)
            {
                throw new TransactionException("It looks like we couldn't find your request! Try clicking the link in the email again, or email us at accounts-enwiki-l@lists.wikimedia.org for assistance", "Ooops!");
            }
        
            $request->confirmEmail($_GET['si']);
            $request->save();
            
            $logStatement = $database->prepare("INSERT INTO acc_log (log_pend, log_user, log_action, log_time) VALUES (:request, :requestname, 'Email Confirmed', CURRENT_TIMESTAMP());");
            $logStatement->bindValue(":request", $request->getId());
            $logStatement->bindValue(":requestname", $request->getName());
            $logStatement->execute();
        });
        
        $smarty->display("request/email-confirmed.tpl");
        
        $request = Request::getById($_GET['id'], $database);
        Notification::requestReceived($request);
        
        BootstrapSkin::displayPublicFooter();
    }
    catch(Exception $ex)
    {
        BootstrapSkin::displayAlertBox($ex->getMessage(), "alert-error", "Unknown error", true, false);
        BootstrapSkin::displayPublicFooter();
    }
}
else
{
    if($_SERVER['REQUEST_METHOD'] == "POST")
    {
        $errorEncountered = false;
        
        $request = new Request();
        $request->setDatabase($database);
        
        $request->setName($_POST['name']);
        $request->setEmail($_POST['email']);
        $request->setComment($_POST['comments']);
        $request->setIp($_SERVER['REMOTE_ADDR']);
        
        if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        {
            $request->setForwardedIp($_SERVER['HTTP_X_FORWARDED_FOR']);
        }
        
        if(isset($_SERVER['HTTP_USER_AGENT']))
        {
            $request->setUserAgent($_SERVER['HTTP_USER_AGENT']);
        }
        
        $validationHelper = new RequestValidationHelper(new BanHelper(), $request, $_POST['emailconfirm']);
        
        // These are arrays of ValidationError.
        $nameValidation = $validationHelper->validateName();
        $emailValidation = $validationHelper->validateEmail();
        $otherValidation = $validationHelper->validateOther();
        
        $validationErrors = array_merge($nameValidation, $emailValidation, $otherValidation);
        
        if(count($validationErrors) > 0)
        {
            foreach ($validationErrors as $validationError)
            {
                BootstrapSkin::displayAlertBox($smarty->fetch("validation/" . $validationError->getErrorCode() . ".tpl"), "alert-error");
            }
            
            $smarty->display("request/request-form.tpl");
        }
        else
        {
            $request->generateEmailConfirmationHash();

            $database->transactionally(function() use($request)
            {
                $request->save();

                // checksum depends on the ID, so we have to save again!
                $request->updateChecksum();
                $request->save();
            });
            
            $request->sendConfirmationEmail();
            
            $smarty->display("request/email-confirmation.tpl");
        }
        
        BootstrapSkin::displayPublicFooter();
    }
    else
    {
        $smarty->display("request/request-form.tpl");
        BootstrapSkin::displayPublicFooter();
    }
}