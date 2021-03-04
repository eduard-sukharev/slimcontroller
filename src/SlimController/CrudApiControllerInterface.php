<?php

namespace SlimController;

interface CrudApiControllerInterface
{
    public function readAction();

    /**
     * @param string|int $id This parameter name is important!
     */
    public function getOneAction($id);

    public function createAction();

    /**
     * @param string|int $id This parameter name is important!
     */
    public function updateOneAction($id);

    public function updateMultipleAction();

    /**
     * @param string|int $id This parameter name is important!
     */
    public function deleteAction($id);
}
