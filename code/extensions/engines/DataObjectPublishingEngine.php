<?php
/**
 * This extension couples to DataObject objects and makes sure the actual change
 * to DataObject is triggered/enqueued.
 *
 * Provides the following information as a context to the DataObject:
 * * action - name of the executed action: write or delete
 *
 * @see PublishableSiteTree
 */

class DataObjectPublishingEngine extends PublishingEngine
{
    /**
     * Event handler for after a write of the DataObject.
     */
	public function onAfterWrite()
    {
        $context = [
            PublishingEngine::CONTEXT_ACTION => PublishingEngine::CONTEXT_ACTION_PUBLISH
        ];

        /* For certain data objects, some items can exist and yet we don't want them to be publishable,
           so if they define the function and if it returns false, switch to unpublish. */
        if (is_callable([$this->owner, 'isPublishable'])) {
          if (!$this->owner->isPublishable()) {
              $context[PublishingEngine::CONTEXT_ACTION] = PublishingEngine::CONTEXT_ACTION_UNPUBLISH;
          }
        }

		$this->collectChanges($context);
		$this->flushChanges();
	}

    /**
     * Event handler for before a delete of the DataObject.
     */
    public function onBeforeDelete()
    {
        $context = [
            PublishingEngine::CONTEXT_ACTION => PublishingEngine::CONTEXT_ACTION_UNPUBLISH
        ];
        $this->collectChanged($context);
    }

    /**
     * Event handler for after a delete of the DataObject.
     */
	public function onAfterDelete()
    {
		$this->flushChanges();
	}
}
