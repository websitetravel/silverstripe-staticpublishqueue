<?php
/**
 * This extension couples to the StaticallyPublishable and StaticPublishingTrigger implementations
 * on the SiteTree objects and makes sure the actual change to SiteTree is triggered/enqueued.
 *
 * Provides the following information as a context to StaticPublishingTrigger:
 * * action - name of the executed action: publish or unpublish
 *
 * @see PublishableSiteTree
 */

class SiteTreePublishingEngine extends PublishingEngine
{
    /**
     * Event handler for after a publish of the SiteTree.
     */
	public function onAfterPublish()
    {
		$context = [
            PublishingEngine::CONTEXT_ACTION => PublishingEngine::CONTEXT_ACTION_PUBLISH
		];
		$this->collectChanges($context);
		$this->flushChanges();
	}

    /**
     * Event handler for before an unpublish of the SiteTree.
     */
	public function onBeforeUnpublish()
    {
		$context = [
            PublishingEngine::CONTEXT_ACTION => PublishingEngine::CONTEXT_ACTION_UNPUBLISH
		];
		$this->collectChanges($context);
	}

    /**
     * Event handler for after an unpublish of the SiteTree.
     */
	public function onAfterUnpublish()
    {
		$this->flushChanges();
	}
}
