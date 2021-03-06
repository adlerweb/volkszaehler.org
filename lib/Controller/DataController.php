<?php
/**
 * @copyright Copyright (c) 2011, The volkszaehler.org project
 * @package default
 * @license http://www.opensource.org/licenses/gpl-license.php GNU Public License
 */
/*
 * This file is part of volkzaehler.org
 *
 * volkzaehler.org is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * volkzaehler.org is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with volkszaehler.org. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Volkszaehler\Controller;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManager;

use Volkszaehler\Model;
use Volkszaehler\Util;
use Volkszaehler\Interpreter\Interpreter;

/**
 * Data controller
 *
 * @author Steffen Vogel <info@steffenvogel.de>
 * @package default
 */
class DataController extends Controller {

	const OPT_SKIP_DUPLICATES = 'skipduplicates';

	protected $ec;	// EntityController instance
	protected $options;	// optional request parameters

	public function __construct(Request $request, EntityManager $em) {
		parent::__construct($request, $em);

		$this->options = self::makeArray(strtolower($this->request->query->get('options')));
		$this->ec = new EntityController($this->request, $this->em);
	}

	/**
	 * Query for data by given channel or group or multiple channels
	 *
	 * @param string|array uuid
	 */
	public function get($uuid) {
		$from = $this->request->query->get('from');
		$to = $this->request->query->get('to');
		$tuples = $this->request->query->get('tuples');
		$groupBy = $this->request->query->get('group');

		// single UUID
		if (is_string($uuid)) {
			$entity = $this->ec->getSingleEntity($uuid, true); // from cache
			$class = $entity->getDefinition()->getInterpreter();
			return new $class($entity, $this->em, $from, $to, $tuples, $groupBy, $this->options);
		}

		// multiple UUIDs
		return array_map(function($uuid) {
			return $this->get($uuid);
		}, self::makeArray($uuid));
	}

	/**
	 * Add single or multiple tuples
	 *
	 * @todo deduplicate Model\Channel code
	 * @param string|array uuid
	 */
	public function add($uuid) {
		$channel = $this->ec->getSingleEntity($uuid, true);

		try { /* to parse new submission protocol */
			$rawPost = $this->request->getContent(); // file_get_contents('php://input')
			$json = Util\JSON::decode($rawPost);

			if (isset($json['data'])) {
				throw new \Exception('Can only add data for a single channel at a time'); /* backed out b111cfa2 */
			}

			// convert nested ArrayObject to plain array with flattened tuples
			$data = array_reduce($json, function($carry, $tuple) {
				return array_merge($carry, $tuple);
			}, array());
		}
		catch (\RuntimeException $e) { /* fallback to old method */
			$timestamp = $this->request->query->get('ts');
			$value = $this->request->query->get('value');

			if (is_null($timestamp)) {
				$timestamp = (double) round(microtime(TRUE) * 1000);
			}
			else {
				$timestamp = Interpreter::parseDateTimeString($timestamp);
			}

			if (is_null($value)) {
				$value = 1;
			}

			// same structure as JSON request result
			$data = array($timestamp, $value);
		}

		$sql = 'INSERT ' . ((in_array(self::OPT_SKIP_DUPLICATES, $this->options)) ? 'IGNORE ' : '') .
			   'INTO data (channel_id, timestamp, value) ' .
			   'VALUES ' . implode(', ', array_fill(0, count($data)>>1, '(' . $channel->getId() . ',?,?)'));

		$rows = $this->em->getConnection()->executeUpdate($sql, $data);
		return array('rows' => $rows);
	}

	/**
	 * Delete tuples from single or multiple channels
	 *
	 * @todo deduplicate Model\Channel code
	 * @param string|array uuid
	 */
	public function delete($uuids) {
		$from = null;
		$to = null;

		// parse interval
		if (null !== ($from = $this->request->query->get('from'))) {
			$from = Interpreter::parseDateTimeString($from);

			if (null !== ($to = $this->request->query->get('to'))) {
				$to = Interpreter::parseDateTimeString($to);

				if ($from > $to) {
					throw new \Exception('From is larger than to');
				}
			}
		}
		elseif ($from = $this->request->query->get('ts')) {
			$to = $from;
		}
		else {
			throw new \Exception('Missing timestamp (ts, from, to)');
		}

		$rows = 0;

		foreach (self::makeArray($uuids) as $uuid) {
			$channel = $this->ec->getSingleEntity($uuid, true);
			$rows += $channel->clearData($this->em->getConnection(), $from, $to);
		}

		return array('rows' => $rows);
	}
}

?>
