<?php

/**
 * Vvveb
 *
 * Copyright (C) 2022  Ziadin Givan
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Vvveb\Plugins\ImportWordpress\Controller;

use \Vvveb\System\Import\Rss;
use \Vvveb\System\Import\Sql;
use \Vvveb\System\Import\Xml;
use function Vvveb\__;
use Vvveb\Controller\Base;
use function Vvveb\htmlToText;
use function Vvveb\nl2p;
use Vvveb\Sql\categorySQL;
use Vvveb\Sql\postSQL;

class Settings extends Base {
	private $cats = [];

	private $postTypes = ['post', 'page', 'attachment'];

	function formatHtml($html) {
		//remove comments
		$html = preg_replace('/<!-- .+? -->/mi', '', $html);
		//fix align classes
		$html = str_replace(
		['aligncenter', 'alignleft', 'alignright', '<ul class="blocks-gallery-grid">', '<li class="blocks-gallery-item">'],
		['align-center', 'align-left', 'align-right', '<ul class="list-unstyled">', '<li>'],
		$html);

		return $html;
	}

	private function addCategories($slug, $name) {
		$cat = $this->categories->getCategoryBySlug(['slug' => $category_slug, 'taxonomy_id' => 1, 'parent_id' => $prev_category, 'site_id' => $this->global['site_id']]);

		if ($cat) {
			$prev_category = $cat['taxonomy_item_id'];
		} else {
			$cat = $this->categories->addCategory([
				'taxonomy_item' => $this->global + [
					'parent_id' => $prev_category,
				],
				'taxonomy_item_content' => $this->global + ['slug'=> $slug, 'name' => $name, 'content' => ''],
			] + $this->global);

			$category_id = $cat['taxonomy_item'];
		}
	}

	private function add($data) {
		//$category_id = $this->cats[$category] ?? false;
		//$post_data   = $this->post->get(['slug' => $data['slug']]) ?? [];
		$post_data            = false;
		$category_id          = false;
		$data['excerpt']      = $data['excerpt'] ? $data['excerpt'] : '';//substr(htmlToText($data['content']), 0, 200);

		if (! $post_data) {
			$data =
				[
					'post' => $this->global + $data + ['post_content' => [
						1 => $data + $this->global,
					]],
				] + $this->global;

			$post_data = $this->post->add($data);

			if ($category_id) {
				$taxonomy_item = ['post_id' => $post_data['post'], 'taxonomy_item' => ['taxonomy_item_id' => $category_id]];
				$this->post->setPostTaxonomy($taxonomy_item);
			}
		} else {
			//if slug already exists update post
			$data = $this->global + [
				'post' => $post_data + $data + ['post_content' => [
					1=> $post_data + $data,
				]],
				'post_id' => $post_data['post_id'],
			];
			$result = $this->post->edit($data);

			if ($category_id) {
				$taxonomy_item = ['post_id' => $post_data['post_id'], 'taxonomy_item' => ['taxonomy_item_id' => $category_id]];
				$this->post->setPostTaxonomy($taxonomy_item);
			}
		}
	}

	private function processPost($posts) {
		foreach ($posts as &$post) {
			$data['content']          = nl2p($this->formatHtml($post['content:encoded']));
			$data['excerpt']          = $this->formatHtml($post['excerpt:encoded']);
			$data['name']             = $post['title'];
			$data['slug']             = $post['wp:post_name'];
			$data['type']             = $post['wp:post_type'];
			$data['created_at']       = $post['wp:post_date'];
			$data['updated_at']       = $post['wp:post_date'];
			$data['status']           = $post['wp:status'];
			$this->add($data);
		}
	}

	private function processPage($posts) {
		return $this->processPost($posts);
	}

	private function processAttachment($posts) {
	}

	private function import($file) {
		$this->categories  = new categorySQL();
		$this->post        = new postSQL();

		$rss  = new Rss(file_get_contents($file));

		foreach ($this->postTypes as $postType) {
			$posts = $rss->get(null, null, [['wp:post_type' => $postType]]);
			$fn    = 'process' . ucfirst($postType);
			$this->$fn($posts);
		}

		return true;
	}

	function importFile($file, $name = '') {
		$result = false;

		if ($file) {
			try {
				// use temorary file, php cleans temporary files on request finish.
				$result = $this->import($file);
			} catch (\Exception $e) {
				$error                = $e->getMessage();
				$this->view->errors[] = $error;
			}
		}

		if ($result) {
			$successMessage          = sprintf(__('Import `%s` was successful!'), $name);
			$this->view->success[]   = $successMessage;
		} else {
			$errorMessage           = sprintf(__('Failed to import `%s` file!'), $name);
			$this->view->errors[]   = $errorMessage;
		}
	}

	function upload() {
		$files = $this->request->files;

		//check for uploaded files
		if ($files) {
			foreach ($files as $file) {
				$this->importFile($file['tmp_name'], $file['name']);
			}
		}

		//check if filename is given (from cli)
		$file = $this->request->post['file'] ?? false;

		if (is_array($file)) {
			foreach ($file as $f) {
				$this->importFile($f, basename($f));
			}
		} else {
			if ($file) {
				$this->importFile($file, basename($file));
			}
		}

		return $this->index();
	}

	function index() {
	}
}
