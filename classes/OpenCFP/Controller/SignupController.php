<?php
namespace OpenCFP\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Cartalyst\Sentry\Users\UserExistsException;
use OpenCFP\Form\SignupForm;
use Intervention\Image\Image;
use OpenCFP\Config\ConfigINIFileLoader;

class SignupController
{
    public function getFlash(Application $app)
    {
        $flash = $app['session']->get('flash');
        $this->clearFlash($app);
        return $flash;
    }

    public function clearFlash(Application $app)
    {
        $app['session']->set('flash', null);
    }

    public function indexAction(Request $req, Application $app)
    {
        // Nobody can login after CFP deadline
        $loader = new ConfigINIFileLoader(APP_DIR . '/config/config.' . APP_ENV . '.ini');
        $config_data = $loader->load();

        if (strtotime($config_data['application']['enddate'] . ' 11:59 PM') < strtotime('now')) {

            $app['session']->set('flash', array(
                    'type' => 'error',
                    'short' => 'Error',
                    'ext' => 'Sorry, the call for papers has ended.',
                ));

            return $app->redirect($app['url']);
        }

        // Reset our user to make sure nothing weird happens
        if ($app['sentry']->check()) {
            $app['sentry']->logout();
        }

        $template = $app['twig']->loadTemplate('user/create.twig');
        $form_data = array();
        $form_data['transportation'] = 0;
        $form_data['hotel'] = 0;
        $form_data['formAction'] = '/signup';
        $form_data['buttonInfo'] = 'Create my speaker profile';

        return $template->render($form_data);
    }


    public function processAction(Request $req, Application $app)
    {
        $form_data = array(
            'formAction' => '/signup',
            'first_name' => $req->get('first_name'),
            'last_name' => $req->get('last_name'),
            'company' => $req->get('company'),
            'twitter' => $req->get('twitter'),
            'email' => $req->get('email'),
            'password' => $req->get('password'),
            'password2' => $req->get('password2'),
            'airport' => $req->get('airport'),
            'buttonInfo' => 'Create my speaker profile'
        );
        $form_data['speaker_info'] = $req->get('speaker_info') ?: null;
        $form_data['speaker_bio'] = $req->get('speaker_bio') ?: null;
        $form_data['transportation'] = $req->get('transportation') ?: null;
        $form_data['hotel'] = $req->get('hotel') ?: null;
        $form_data['speaker_photo'] = null;

        if ($req->files->get('speaker_photo') !== null) {
            $form_data['speaker_photo'] = $req->files->get('speaker_photo');
        }

        $form = new SignupForm($form_data, $app['purifier']);
        $form->sanitize();
        $isValid = $form->validateAll();

        if ($isValid) {
            $sanitized_data = $form->getCleanData();

            // process the speaker photo
            $this->processSpeakerPhoto($form_data, $app);

            // Remove leading @ for twitter
            $sanitized_data['twitter'] = preg_replace(
                '/^@/',
                '',
                $sanitized_data['twitter']
            );
            // Create account using Sentry
            try {
                $user_data = array(
                    'first_name' => $sanitized_data['first_name'],
                    'last_name' => $sanitized_data['last_name'],
                    'company' => $sanitized_data['company'],
                    'twitter' => $sanitized_data['twitter'],
                    'email' => $sanitized_data['email'],
                    'password' => $sanitized_data['password'],
                    'airport' => $sanitized_data['airport'],
                    'activated' => 1
                );

                $user = $app['sentry']->getUserProvider()->create($user_data);

                // Add them to the proper group
                $user->addGroup($app['sentry']
                    ->getGroupProvider()
                    ->findByName('Speakers')
                );

                // Add in the extra speaker information
                $mapper = $app['spot']->mapper('\OpenCFP\Entity\User');

                $speaker = $mapper->get($user->id);
                $speaker->info = $sanitized_data['speaker_info'];
                $speaker->bio = $sanitized_data['speaker_bio'];
                $speaker->photo_path = $sanitized_data['speaker_photo'];
                $mapper->save($speaker);

                // Set Success Flash Message
                $app['session']->set('flash', array(
                    'type' => 'success',
                    'short' => 'Success',
                    'ext' => "You've successfully created your account!",
                ));

                return $app->redirect($app['url'] . '/login');
            } catch (UserExistsException $e) {
                $app['session']->set('flash', array(
                        'type' => 'error',
                        'short' => 'Error',
                        'ext' => 'A user already exists with that email address'
                    ));
            }
        }

        if (!$isValid) {
            // Set Error Flash Message
            $app['session']->set('flash', array(
                    'type' => 'error',
                    'short' => 'Error',
                    'ext' => implode("<br>", $form->getErrorMessages())
                ));
        }

        $template = $app['twig']->loadTemplate('user/create.twig');
        $form_data['flash'] = $this->getFlash($app);
        return $template->render($form_data);
    }

    /**
     * Process any speaker photos that we might have
     *
     * @param array $form_data
     * @param Application $app
     */
    protected function processSpeakerPhoto($form_data, Application $app)
    {
        if (!isset($form_data['speaker_photo'])) {
            return false;
        }

        // Move file into uploads directory
        $fileName = uniqid() . '_' . $form_data['speaker_photo']->getClientOriginalName();
        $form_data['speaker_photo']->move(APP_DIR . '/web/' . $app['uploadPath'], $fileName);

        // Resize Photo
        $speakerPhoto = Image::make(APP_DIR . '/web/' . $app['uploadPath'] . '/' . $fileName);

        if ($speakerPhoto->height > $speakerPhoto->width) {
            $speakerPhoto->resize(250, null, true);
        } else {
            $speakerPhoto->resize(null, 250, true);
        }

        $speakerPhoto->crop(250, 250);

        // Give photo a unique name
        $sanitized_data['speaker_photo'] = $form_data['first_name'] . '.' . $form_data['last_name'] . uniqid() . '.' . $speakerPhoto->extension;

        // Resize image, save, and destroy original
        if (!$speakerPhoto->save(APP_DIR . '/web/' . $app['uploadPath'] . $sanitized_data['speaker_photo'])) {
            return false;
        }

        unlink(APP_DIR . '/web/' . $app['uploadPath'] . $fileName);

        return true;
    }

}
