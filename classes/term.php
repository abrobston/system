<?php

/**
 * Term Class
 *
 *
 * @version $Id$
 * @copyright 2009
 */

class Term extends QueryRecord
{
	// static variable to hold the vocabulary this term belongs to
	private $vocabulary = null;

	/**
	 * Return the defined database columns for a Term.
	 * @return array Array of columns in the Term table
	 **/
	public static function default_fields()
	{
		return array(
			'id' => 0,
			'term' => '',
			'term_display' => '',
			'vocabulary_id' => 0,
			'mptt_left' => 0,
			'mptt_right' => 0
		);
	}

	/**
	 * Term constructor
	 * Creates a Term instance
	 *
	 * @param array $paramarray an associative array of initial term values
	 **/
	public function __construct( $paramarray = array() )
	{
		// Defaults
		$this->fields = array_merge(
			self::default_fields(),
			$this->fields
		);
		
		if(is_string($paramarray)) {
			$paramarray = array(
				'term_display' => $paramarray,
				'term' => Utils::slugify($paramarray),
			);
		}

		parent::__construct( $paramarray );

		// TODO does term need to be a slug ?
		// TODO How should we handle neither being present ?
		// A Vocabulary may be used for organization, display, or both.
		// Therefore, a Term can be constructed with a term, term_display, or both
		if ( $this->term == '' ) {
			$this->fields['term'] = Utils::slugify($this->fields['term_display']);
		}

		$this->exclude_fields( 'id' );
	}
	
	/**
	 * Fetch a term from a specified vocabulary
	 * 
	 * @param mixed $vocab_id The id of a vocabulary, or a Vocabulary object
	 * @param mixed $term_id A Term id or null for the root node of the vocabulary
	 * @return Term The requested Term object instance
	 */
	public static function get($vocab_id, $term_id = null)
	{
		if($vocab_id instanceof Vocabulary) {
			$vocab_id = $vocab_id->id;
		}
		$params = array($vocab_id);
		$query = '';
		if(is_null($term_id)) {
			// The root node has an mptt_left value of 1
			$params[] = 1;
			$query = 'SELECT * FROM {terms} WHERE vocabulary_id=? AND mptt_left=?';
		}
		else {
			$params[] = $term_id;
			$query = 'SELECT * FROM {terms} WHERE vocabulary_id=? AND id=?';
		}
		return DB::get_row( $query, $params, 'Term' );
	}

	/**
	 * function insert
	 * Saves a new term to the terms table
	 */
	public function insert()
	{
		// Let plugins disallow and act before we write to the database
		$allow = true;
		$allow = Plugins::filter( 'term_insert_allow', $allow, $this );
		if ( !$allow ) {
			return false;
		}
		Plugins::act( 'term_insert_before', $this );

		$result = parent::insertRecord( '{terms}' );

		// Make sure the id is set in the term object to match the row id
		$this->newfields['id'] = DB::last_insert_id();

		// Update the term's fields with anything that changed
		$this->fields = array_merge( $this->fields, $this->newfields );

		// We've inserted the term, reset newfields
		$this->newfields = array();

		EventLog::log( sprintf(_t('New term %1$s (%2$s)'), $this->id, $this->name), 'info', 'content', 'habari' );

		// Let plugins act after we write to the database
		Plugins::act( 'term_insert_after', $this );

		return $result;
	}

	/**
	 * function update
	 * Updates an existing term in the terms table
	 */
	public function update()
	{
		// Let plugins disallow and act before we write to the database
		$allow = true;
		$allow = Plugins::filter( 'term_update_allow', $allow, $this );
		if ( !$allow ) {
			return;
		}
		Plugins::act( 'term_update_before', $this );

		$result = parent::updateRecord( '{terms}', array( 'id' => $this->id ) );

		// Let plugins act after we write to the database
		Plugins::act( 'term_update_after', $this );

		return $result;
	}

	/**
	 * Delete an existing term
	 */
	public function delete()
	{
		// Let plugins disallow and act before we write to the database
		$allow = true;
		$allow = Plugins::filter( 'term_delete_allow', $allow, $this );
		if ( !$allow ) {
			return false;
		}
		Plugins::act( 'term_delete_before', $this );

		$result = parent::deleteRecord( '{terms}', array( 'id'=>$this->id ) );
		EventLog::log( sprintf(_t('Term %1$s (%2$s) deleted.'), $this->id, $this->name), 'info', 'content', 'habari' );

		// Let plugins act after we write to the database
		Plugins::act( 'term_delete_after', $this );
		return $result;
	}

	/**
	 * Find this Term's ancestors.
	 * @return Array Direct ancestors from the root to this Term in descendant order.
	 **/
	public function ancestors()
	{
		// TODO There should probably be a Term::get()
		$params = array($this->vocabulary_id, $this->mptt_left, $this->mptt_right );
		$query = 'SELECT * FROM {terms} WHERE vocabulary_id=? AND mptt_left<? AND mptt_right>? ORDER BY mptt_left ASC';
		return DB::get_results( $query, $params, 'Term' );
	}

	/**
	 * Find this Term's descendants.
	 * @return Array of all descendants in MPTT left-to-right order.
	 **/
	public function descendants()
	{
		// TODO There should probably be a Term::get()
		$params = array($this->vocabulary_id, $this->mptt_left, $this->mptt_right);
		$query = 'SELECT * FROM {terms} WHERE vocabulary_id=? AND mptt_left>? AND mptt_right<? ORDER BY mptt_left ASC';
		return DB::get_results( $query, $params, 'Term' );
	}

	/**
	 * The Term that is this Term's parent in hierarchy.
	 * @return Term This Term's parent
	 **/
	public function parent()
	{
		// TODO There should probably be a Term::get()
		$params = array($this->vocabulary_id, $this->mptt_left, $this->mptt_right);
		$query = 'SELECT * FROM {terms} WHERE vocabulary_id=? AND mptt_left<? AND mptt_right>? ORDER BY mptt_left DESC LIMIT 1';
		return DB::get_row( $query, $params, 'Term' );
	}

	/**
	 * Find this Term's siblings.
	 * @return Array of all siblings including self.
	 **/
	public function siblings()
	{
		return $this->parent()->children();
	}

	/**
	 * Find this Term's children.
	 * @return Array of all direct children (compare to descendants()).
	 **/
	public function children()
	{
		$params = array( 'vocab' => $this->vocabulary_id,
			'left' => $this->mptt_left,
			'right' => $this->mptt_right
		);
		/**
		 * If we INNER JOIN the terms table with itself on ALL the descendents of our term,
		 * then descendents one level down are listed once, two levels down are listed twice,
		 * etc. If we return only those terms which appear once, we get immediate children.
		 * ORDER BY NULL to avoid the MySQL filesort.
		 */
		$query = <<<SQL
SELECT child.term as term,
	child.term_display as term_display,
	child.mptt_left as mptt_left,
	child.mptt_right as mptt_right,
	child.vocabulary_id as vocabulary_id
FROM {terms} as parent
INNER JOIN {terms} as child
	ON child.mptt_left BETWEEN parent.mptt_left AND parent.mptt_right
	AND child.vocabulary_id = parent.vocabulary_id
WHERE parent.mptt_left > :left AND parent.mptt_right < :right
AND parent.vocabulary_id = :vocab
GROUP BY child.term
HAVING COUNT(child.term)=1
ORDER BY NULL
SQL;
		return DB::get_results( $query, $params, 'Term' );
	}

	/**
	 * Find objects of a given type associated with this Term.
	 *
	 * @return Array of object ids associated with this term for the given type.
	 **/
	public function objects($type)
	{
	}

	/**
	 * Associate this term to an object of a certain type via its id.
	 *
	 **/
	public function associate($type, $id)
	{
	}

}



?>