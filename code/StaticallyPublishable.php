<?php
/**
 * Interface for statically publishable objects. It does not define how change is triggered,
 * just that the implementing class has some related objects it might wish to update
 * (objectsTo* methods) and that it has a family of URLs that it needs to maintain (urlsToCache).
 *
 * It is expected that a full cache can be rebuilt by finding all objects that implement
 * this interface, and calling urlsToCache on these. This implies that any URL should belong
 * to just one object.
 *
 * Available context information:
 * * action - name of the executed action: publish or unpublish
 */

interface StaticallyPublishable {

	/**
	 * Provides an SS_List of StaticallyPublishable objects which need to be regenerated.
	 *
	 * @param $context An associative array with information on the action.
	 *
	 * @return DataList of Page.
	 */
	public function objectsToUpdate($context);

	/**
	 * Provides a SS_list of objects that need to be deleted.
	 *
	 * @param $context An associative array with information on the action.
	 *
	 * @return DataList of Page.
	 */
	public function objectsToDelete($context);

	/**
	 * Get a list of URLs that this object wishes to maintain. URLs should not overlap with other objects.
	 *
	 * Note: include the URL of the object itself!
	 *
	 * @return array associative array of URL (string) => Priority (int)
	 */
	public function urlsToCache();

}
