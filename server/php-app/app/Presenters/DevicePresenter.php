<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\Random;
use Nette\Utils\DateTime;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use Nette\Utils\Strings;
use Nette\Utils\FileSystem;
use Nette\Application\UI;
use Nette\Application\UI\Form;
use Nette\Http\Url;
use Nette\Application\Responses\FileResponse;

use App\Services\Logger;
use \App\Services\InventoryDataSource;
use \App\Services\Config;

final class DevicePresenter extends BaseAdminPresenter
{
    use Nette\SmartObject;

    /** @persistent */
	public $viewid = "";
    
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    /** @var \App\Services\Config */
    private $config;

    public function __construct(\App\Services\InventoryDataSource $datasource, 
                                \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->config = $config;
        $this->links = $config->links;
        $this->appName = $config->appName;
    }


    public function renderCreate()
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
    }


    protected function createComponentDeviceForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addGroup('Základní údaje');

        $form->addText('name', 'Identifikátor (jméno):')
            ->setRequired()
            ->addRule(Form::PATTERN, 'Jen písmena a čísla', '([0-9A-Za-z]+)')
            ->setOption('description', 'Toto jméno doplněné prefixem bude používáno pro přihlašování zařízení.'  )
            ->setAttribute('size', 50)
            ;

        $form->addText('passphrase', 'Komunikační heslo:')
            ->setAttribute('size', 50)
            ->setRequired();

        $form->addTextArea('desc', 'Popis:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addGroup('Přístup k datům bez přihlášení');

        $form->addText('json_token', 'Bezpečnostní token pro data:')
            ->setOption('description', 'Pokud je vyplněn, kdokoli se znalostí správné adresy se může podívat na JSON s daty. Má smysl jen v případě, že má zařízení nějaké senzory.'  )
            ->setAttribute('size', 50)
            ->setDefaultValue( Random::generate(40) );

        $form->addText('blob_token', 'Bezpečnostní token pro galerii:')
            ->setOption('description', 'Pokud je vyplněn, kdokoli se znalostí správné adresy se může podívat na galerii obrázků. Má smysl jen tehdy, pokud zařízení nahrává obrázky.'  )
            ->setAttribute('size', 50)
            ->setDefaultValue( Random::generate(40) );

        $form->addGroup('Monitoring');

        $form->addCheckbox('monitoring', 'Zařadit do monitoringu funkce')
            ->setOption('description', 'Pokud ze senzorů zařízení nebudou chodit data tak často, jak mají, bude zaslána notifikace.'  )
            ->setDefaultValue(false);

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'deviceFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    
    public function deviceFormSucceeded(Form $form, array $values): void
    {
        $values['name'] = "{$this->getUser()->getIdentity()->prefix}:{$values['name']}";
        $values['user_id'] = $this->getUser()->id;

        $values['passphrase'] = $this->config->encrypt( $values['passphrase'], $values['name'] );

        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getDevice( $id );
            if (!$device) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id );
            $device->update( $values );
        } else {
            // zalozeni
            $this->datasource->createDevice( $values );
        }

        $this->flashMessage("Změny provedeny.", 'success');
        if( $id ) {
            $this->redirect("Device:show", $id );
        } else {
            $this->redirect("Inventory:home" );
        }
    }
    
    public function actionEdit(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $post['passphrase'] = $this->config->decrypt( $post['passphrase'], $post['name'] );
        $this->checkAcces( $post['user_id'] );

        $this->template->name = $post['name'];

        $arr = Strings::split($post['name'], '~:~');
        $post['name'] = $arr[1];

        $this['deviceForm']->setDefaults($post);
    }

    //----------------------------------------------------------------------



    public function renderShow(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $post['passphrase'] = $this->config->decrypt( $post['passphrase'], $post['name'] );
        $this->checkAcces( $post['user_id'] );

        $post['problem_mark'] = false;
        if( $post['last_bad_login'] != NULL ) {
            if( $post['last_login'] != NULL ) {
                $lastLoginTs = (DateTime::from( $post['last_login']))->getTimestamp();
                $lastErrLoginTs = (DateTime::from(  $post['last_bad_login']))->getTimestamp();
                if( $lastErrLoginTs >  $lastLoginTs ) {
                    $post['problem_mark'] = true;
                }
            } else {
                $post['problem_mark'] = true;
            }
        }

        $blobCount = $this->datasource->getBlobCount( $id );

        $submenu = array();
        $submenu[] =   ['id' => '102', 'link' => "device/show/{$id}", 'name' => "· Zařízení {$post['name']}" ];
        if( $blobCount>0 ) {
            $submenu[] =   ['id' => '103', 'link' => "device/blobs/{$id}", 'name' => "· · Soubory ({$blobCount})" ];
        }
        $this->populateTemplate( 102, 1, $submenu );
        $this->template->path = '../';

        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $url->setScheme('http');
        $url1 = $url->getAbsoluteUrl() . 'ra';
        $this->template->url = substr( $url1 , 7 );

        $this->template->device = $post;
        $this->template->soubory = $blobCount;

        $this->template->sensors = $this->datasource->getDeviceSensors( $id );

        foreach ($this->template->sensors as $sensor) {
            $sensor['warningIcon'] = 0;
            if( $sensor['last_data_time'] ) {
                $utime = (DateTime::from( $sensor['last_data_time'] ))->getTimestamp();
                if( time()-$utime > $sensor['msg_rate'] ) {
                    if( $post['monitoring']==1 ) {
                        $sensor['warningIcon'] = 1;
                    } else {
                        $sensor['warningIcon'] = 2;
                    }
                } 
            }
        }

        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $this->template->jsonUrl = $url->getAbsoluteUrl() . "json/data/{$post['json_token']}/{$id}/";
        $this->template->blobUrl = $url->getAbsoluteUrl() . "gallery/{$post['blob_token']}/{$id}/";

    }


    public function renderBlobs(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $blobs = $this->datasource->getBlobs( $id );
        $blobCount = count( $blobs );

        $submenu = array();
        $submenu[] =   ['id' => '102', 'link' => "device/show/{$id}", 'name' => "· Zařízení {$post['name']}" ];
        $submenu[] =   ['id' => '103', 'link' => "device/blobs/{$id}", 'name' => "· · Soubory ({$blobCount})" ];
        $this->populateTemplate( 103, 1, $submenu );
        $this->template->path = '../';

        $this->template->id = $id;
        $this->template->device = $post;
        $this->template->soubory = $blobCount;
        $this->template->blobs = $blobs;
    }


    public function renderDownload(int $id, int $blobId ): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $blob = $this->datasource->getBlob( $id, $blobId );
        if( ! $blob ) {
            $this->error('Soubor nenalezen nebo k němu nejsou práva.');
        }

        $fileName = 
            $blob['data_time']->format('Ymd_His') . 
            "_{$id}_" .
            Strings::webalize($blob['description'], '._') .
            ".{$blob['extension']}";

        $contentType = 'application/octet-stream';
        if( $blob['extension']=='csv') {
            $contentType = 'text/csv';
        } else if( $blob['extension']=='jpg') {
            $contentType = 'image/jpeg';
        }

        $file = __DIR__ . "/../../data/" . $blob['filename'];
        $response = new FileResponse($file, $fileName, $contentType);
		$this->sendResponse($response);
    }


    //----------------------------------------------------------------------


    public function actionDelete( int $id ): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $this->checkAcces( $post->user_id );

        $this->template->device = $post;
        $this->template->statMeasures = $this->datasource->getDataStatsMeasures( $id );
        $this->template->statSumdata = $this->datasource->getDataStatsSumdata( $id );
        
    }

    protected function createComponentDeleteForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addCheckbox('potvrdit', 'Potvrdit smazání')
            ->setOption('description', 'Zaškrtnutím potvrďte, že skutečně chcete smazat zařízení a všechna data jím zaznamenaná.'  )
            ->setRequired();

        $form->addSubmit('delete', 'Smazat')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'deleteFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function deleteFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');

        if( $id ) {
            // overeni prav
            $post = $this->datasource->getDevice( $id );
            if (!$post) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $post->user_id );
            
            Logger::log( 'audit', Logger::INFO , "[{$this->getHttpRequest()->getRemoteAddress()}, {$this->getUser()->getIdentity()->username}] Mazu zarizeni {$id}" ); 
            $this->datasource->deleteDevice( $id );
        } 

        $this->flashMessage("Zařízení smazáno.", 'success');
        $this->redirect('Inventory:home' );
    }



    //----------------------------------------------------------------------



    public function actionSendconfig(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $this->template->name = $post['name'];

        $arr = Strings::split($post['name'], '~:~');
        $post['name'] = $arr[1];

        $this['sendconfigForm']->setDefaults($post);
    }

    protected function createComponentSendconfigForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addTextArea('config_data', 'Změna konfigurace:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'sendconfigFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    
    public function sendconfigFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getDevice( $id );
            if (!$device) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu device {$id}" );
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id );
            if( ! $device['config_ver'] ) {
                $values['config_ver'] = '1';
            } else {
                $values['config_ver'] = intval($device['config_ver']) + 1;
            }
            $device->update( $values );
            $this->datasource->deleteSession( $id );
            $this->flashMessage("Změny provedeny.", 'success');
            $this->redirect("Device:show", $id );
        } else {
            // zalozeni - to se nema stat
            $this->redirect("Inventory:home" );
        }
    }    
    
}

