<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

abstract class DiffusionQuery {

  private $request;

  final protected function __construct() {
    // <protected>
  }

  protected static function newQueryObject(
    $base_class,
    DiffusionRequest $request) {

    $repository = $request->getRepository();

    $map = array(
      PhabricatorRepositoryType::REPOSITORY_TYPE_GIT        => 'Git',
      PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL  => 'Mercurial',
      PhabricatorRepositoryType::REPOSITORY_TYPE_SVN        => 'Svn',
    );

    $name = idx($map, $repository->getVersionControlSystem());
    if (!$name) {
      throw new Exception("Unsupported VCS!");
    }

    $class = str_replace('Diffusion', 'Diffusion'.$name, $base_class);
    $obj = new $class();
    $obj->request = $request;

    return $obj;
  }

  final protected function getRequest() {
    return $this->request;
  }

  abstract protected function executeQuery();


/* -(  Query Utilities  )---------------------------------------------------- */


  final protected function loadHistoryForCommitIdentifiers(array $identifiers) {
    if (!$identifiers) {
      return array();
    }

    $commits = array();
    $commit_data = array();
    $path_changes = array();

    $drequest = $this->getRequest();
    $repository = $drequest->getRepository();

    $path = $drequest->getPath();

    $commits = id(new PhabricatorRepositoryCommit())->loadAllWhere(
      'repositoryID = %d AND commitIdentifier IN (%Ls)',
        $repository->getID(),
      $identifiers);
    $commits = mpull($commits, null, 'getCommitIdentifier');

    if (!$commits) {
      return array();
    }

    $commit_data = id(new PhabricatorRepositoryCommitData())->loadAllWhere(
      'commitID in (%Ld)',
      mpull($commits, 'getID'));
    $commit_data = mpull($commit_data, null, 'getCommitID');

    $conn_r = $repository->establishConnection('r');

    $path_normal = DiffusionPathIDQuery::normalizePath($path);
    $paths = queryfx_all(
      $conn_r,
      'SELECT id, path FROM %T WHERE pathHash IN (%Ls)',
      PhabricatorRepository::TABLE_PATH,
      array(md5($path_normal)));
    $paths = ipull($paths, 'id', 'path');
    $path_id = idx($paths, $path_normal);

    $path_changes = queryfx_all(
      $conn_r,
      'SELECT * FROM %T WHERE commitID IN (%Ld) AND pathID = %d',
      PhabricatorRepository::TABLE_PATHCHANGE,
      mpull($commits, 'getID'),
      $path_id);
    $path_changes = ipull($path_changes, null, 'commitID');

    $history = array();
    foreach ($identifiers as $identifier) {
      $item = new DiffusionPathChange();
      $item->setCommitIdentifier($identifier);
      $commit = idx($commits, $identifier);
      if ($commit) {
        $item->setCommit($commit);
        $data = idx($commit_data, $commit->getID());
        if ($data) {
          $item->setCommitData($data);
        }
        $change = idx($path_changes, $commit->getID());
        if ($change) {
          $item->setChangeType($change['changeType']);
          $item->setFileType($change['fileType']);
        }
      }
      $history[] = $item;
    }

    return $history;
  }
}
