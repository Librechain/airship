<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Controller;

use Airship\Cabin\Bridge\Model\CustomPages;
use Airship\Cabin\Bridge\Filter\RedirectFilter;

require_once __DIR__.'/init_gear.php';

/**
 * Class Redirects
 * @package Airship\Cabin\Bridge\Controller
 */
class Redirects extends LoggedInUsersOnly
{
    /**
     * @var CustomPages
     */
    protected $pg;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand(): void
    {
        parent::airshipLand();
        /** @var CustomPages $pg */
        $pg = $this->model('CustomPages');
        if (!($pg instanceof CustomPages)) {
            throw new \TypeError(
                \__('Custom Pages Model')
            );
        }
        $this->pg = $pg;
    }

    /**
     * Delete an existing redirect
     *
     * @param string $cabin
     * @param string $redirectId
     */
    public function deleteRedirect(string $cabin, string $redirectId): void
    {
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins) && !$this->can('delete')) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/redirects'
            );
        }
        $this->storeViewVar('active_submenu', ['Cabins', 'Cabin__' . $cabin]);
        $post = $this->post(/* No data is passed */);
        $redirectId = (int) $redirectId;
        $redirect = $this->pg->getRedirect($cabin, $redirectId);

        if (empty($redirect)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/redirects/' . $cabin
            );
        }
        if ($post) {
            if ($this->pg->deleteRedirect($redirectId)) {
                \Airship\redirect(
                    $this->airship_cabin_prefix . '/redirects/' . $cabin
                );
            }
        }
        $this->view(
            'redirect/delete',
            [
                'cabin' => $cabin,
                'redirect' => $redirect
            ]
        );
    }

    /**
     * Edit a redirect.
     *
     * @param string $cabin
     * @param string $redirectId
     * @route redirects/{string}/edit/{id}
     */
    public function editRedirect(string $cabin, string $redirectId): void
    {
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins) && !$this->can('update')) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/redirects'
            );
        }
        $this->setTemplateExtraData($cabin);
        $post = $this->post(new RedirectFilter());
        $redirect = $this->pg->getRedirect($cabin, (int) $redirectId);
        if (empty($redirect)) {
            \Airship\redirect(
                $this->airship_cabin_prefix . '/redirects/' . $cabin
            );
        }
        if ($post) {
            if (\Airship\all_keys_exist(['old_url', 'new_url'], $post)) {
                if ($this->pg->updateRedirect((int) $redirectId, $post)) {
                    \Airship\redirect($this->airship_cabin_prefix . '/redirects/' . $cabin);
                } else {
                    $this->storeViewVar(
                        'form_error',
                        'Could not update redirect. Check that it does not already exist.'
                    );
                }
            }
        }
        $this->view(
            'redirect/edit',
            [
                'cabin' => $cabin,
                'redirect' => $redirect
            ]
        );
    }

    /**
     * List all of the redirects for a given cabin
     *
     * @param string $cabin
     * @route redirects/{string}
     */
    public function forCabin(string $cabin = ''): void
    {
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $this->setTemplateExtraData($cabin);
        $this->view(
            'redirect/for_cabin',
            [
                'cabin' => $cabin,
                'redirects' => $this->pg->getRedirectsForCabin($cabin)
            ]
        );
    }

    /**
     * Serve a submenu of available cabins
     *
     * @route redirects
     */
    public function index(): void
    {
        $this->view(
            'redirect',
            [
                'cabins' => $this->getCabinNamespaces()
            ]
        );
    }

    /**
     * Create a new redirect
     *
     * @param string $cabin
     * @route redirects/{string}/new
     */
    public function newRedirect(string $cabin): void
    {
        $cabins = $this->getCabinNamespaces();
        if (!\in_array($cabin, $cabins) && !$this->can('create')) {
            \Airship\redirect($this->airship_cabin_prefix . '/redirects');
        }
        $this->setTemplateExtraData($cabin);
        $post = $this->post(new RedirectFilter());
        if ($post) {
            if (\Airship\all_keys_exist(['old_url', 'new_url'], $post)) {
                if (\preg_match('#^https?://#', $post['new_url'])) {
                    // Less restrictions:
                    $result = $this->pg->createDifferentCabinRedirect(
                        \trim($post['old_url'], '/'),
                        \trim($post['new_url'], '/'),
                        $cabin
                    );
                } else {
                    $result = $this->pg->createSameCabinRedirect(
                        \trim($post['old_url'], '/'),
                        \trim($post['new_url'], '/'),
                        $cabin
                    );
                }

                if ($result) {
                    \Airship\redirect(
                        $this->airship_cabin_prefix . '/redirects/' . $cabin
                    );
                }
            }
        }
        $this->view(
            'redirect/new',
            [
                'cabin' => $cabin
            ]
        );
    }

    /**
     * Set the cabin links
     *
     * @param string $cabin
     */
    protected function setTemplateExtraData(string $cabin): void
    {
        $this->storeViewVar(
            'active_submenu',
            [
                'Cabins',
                'Cabin__' . $cabin
            ]
        );
        $this->storeViewVar(
            'active_link',
            'bridge-link-cabin-' . $cabin . '-redirects'
        );
    }
}
