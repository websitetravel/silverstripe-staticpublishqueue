<?php
/**
 * Bare-bones implementation of a publishable Data Object.
 *
 * You can override this either by implementing one of the interfaces the class directly, or by applying
 * an extension via the config system ordering (inject your extension "before" the PublishableDataObject).
 *
 * @see SiteDataObjectPublishingEngine
 */

class PublishableDataObject extends DataExtension implements StaticallyPublishable, StaticPublishingTrigger
{
	public function objectsToUpdate($context)
    {
		switch ($context[PublishingEngine::CONTEXT_ACTION]) {
			case PublishingEngine::CONTEXT_ACTION_PUBLISH: {
                // Trigger refresh of the data object itself.
                $list = new ArrayList([$this->owner]);
                return $list;
            }
			case PublishingEngine::CONTEXT_ACTION_UNPUBLISH: {
                return new ArrayList();
            }
		}
	}

	public function objectsToDelete($context)
    {
		switch ($context[PublishingEngine::CONTEXT_ACTION]) {

			case PublishingEngine::CONTEXT_ACTION_PUBLISH: {
                return new ArrayList();
            }
			case PublishingEngine::CONTEXT_ACTION_UNPUBLISH: {
                // Trigger cache removal for this page.
                $list = new ArrayList([$this->owner]);
                return $list;
            }
		}
	}

	/**
	 * Get the URLs to cache. The array must be in the key/value format:
     *   url => priority
     * @return array
	 */
	public function urlsToCache()
    {
        if (is_callable([$this->owner, 'dataObjectUrls'])) {
            return $this->owner->dataObjectUrls();
        }

        return [];
	}
}

