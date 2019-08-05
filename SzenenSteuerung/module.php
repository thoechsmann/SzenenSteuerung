<?php
class SzenenSteuerung extends IPSModule
{

	public function Create()
	{
		//Never delete this line!
		parent::Create();

		//Properties
		$this->RegisterPropertyInteger("SceneCount", 5);
		//Attributes
		$this->RegisterAttributeString("SceneData", "[]");

		if (!IPS_VariableProfileExists("SZS.SceneControl")) {
			IPS_CreateVariableProfile("SZS.SceneControl", 1);
			IPS_SetVariableProfileValues("SZS.SceneControl", 1, 2, 0);
			//IPS_SetVariableProfileIcon("SZS.SceneControl", "");
			IPS_SetVariableProfileAssociation("SZS.SceneControl", 1, "Speichern", "", -1);
			IPS_SetVariableProfileAssociation("SZS.SceneControl", 2, "Ausführen", "", -1);
		}
	}

	public function Destroy()
	{
		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges()
	{
		//Never delete this line!
		parent::ApplyChanges();

		$this->CreateCategoryByIdent($this->InstanceID, "Targets", "Targets");

		$SceneCount = $this->ReadPropertyInteger("SceneCount");

		//Create Scene variables
		for ($i = 1; $i <= $SceneCount; $i++) {
			$variableID = $this->RegisterVariableInteger("Scene" . $i, "Scene" . $i, "SZS.SceneControl");
			$this->EnableAction("Scene" . $i);
			SetValue($variableID, 2);
		}
						
		$SceneData = json_decode($this->ReadAttributeString("SceneData"));
		
		//If older versions contain errors regarding SceneData SceneControl would become unusable otherwise, even in fixed versions
		if (!is_array($SceneData)) {
			$SceneData = [];
		}

		//Preparing SceneData for later use
		$SceneCount = $this->ReadPropertyInteger("SceneCount");

		for ($i = 1; $i <= $SceneCount; $i++) {
			if (!array_key_exists($i - 1, $SceneData)) {
				$SceneData[$i - 1] = new stdClass;
			}
		}
		
		//Getting data from legacy Scene Data to put them in SceneData attribute (including wddx, JSON)
		for ($i = 1; $i <= $SceneCount; $i++) {
			$SceneDataID = @$this->GetIDForIdent("Scene" . $i . "Data");
			if ($SceneDataID) {
				$decodedSceneData = NULL;
				if (function_exists("wddx_deserialize")) {
					$decodedSceneData = wddx_deserialize(GetValue($SceneDataID));
				} 
				
				if ($decodedSceneData == NULL) {
					$decodedSceneData = json_decode(GetValue($SceneDataID));
				}

				if ($decodedSceneData) {
					$SceneData[$i - 1] = $decodedSceneData;
				}
				$this->UnregisterVariable("Scene" . $i . "Data");
			}
		}

		//Deleting surplus data in SceneData
		$SceneData = array_slice($SceneData, 0, $SceneCount);
		$this->WriteAttributeString("SceneData", json_encode($SceneData));

		//Deleting surplus variables
		for ($i = $SceneCount + 1;; $i++) {
			if (@$this->GetIDForIdent("Scene" . $i)) {
				$this->UnregisterVariable("Scene" . $i);
				
			} else {
				break;
			}
		}
	}

	public function RequestAction($Ident, $Value)
	{

		switch ($Value) {
			case "1":
				$this->SaveValues($Ident);
				break;
			case "2":
				$this->CallValues($Ident);
				break;
			default:
				throw new Exception("Invalid action");
		}
	}

	public function CallScene(int $SceneNumber)
	{

		$this->CallValues("Scene" . $SceneNumber);
	}

	public function SaveScene(int $SceneNumber)
	{

		$this->SaveValues("Scene" . $SceneNumber);
	}

	private function SaveValues($SceneIdent)
	{

		$targetIDs = IPS_GetObjectIDByIdent("Targets", $this->InstanceID);
		$data = [];

		//We want to save all Lamp Values
		foreach (IPS_GetChildrenIDs($targetIDs) as $TargetID) {
			//Only allow links
			if (IPS_LinkExists($TargetID)) {
				$linkVariableID = IPS_GetLink($TargetID)['TargetID'];
				if (IPS_VariableExists($linkVariableID)) {
					$data[$linkVariableID] = GetValue($linkVariableID);
				}
			}
		}

		$sceneData = json_decode($this->ReadAttributeString("SceneData"));

		$i = intval(substr($SceneIdent, -1));

		$sceneData[$i - 1] = $data;

		$this->WriteAttributeString("SceneData", json_encode($sceneData));
	}

	private function CallValues($SceneIdent)
	{

		$SceneData = json_decode($this->ReadAttributeString("SceneData"), true);

		$i = intval(substr($SceneIdent, -1));

		$data = $SceneData[$i - 1];

		if (count($data) > 0) {
			foreach ($data as $id => $value) {
				if (IPS_VariableExists($id)) {

					$v = IPS_GetVariable($id);

					if ($v['VariableCustomAction'] > 0) {
						$actionID = $v['VariableCustomAction'];
					} else {
						$actionID = $v['VariableAction'];
					}
					//Skip this device if we do not have a proper id
					if ($actionID < 10000)
						continue;

					RequestAction($id, $value);
				}
			}
		} else {
			echo "No SceneData for this Scene";
		}
	}

	private function CreateCategoryByIdent($id, $ident, $name)
	{
		$cid = @IPS_GetObjectIDByIdent($ident, $id);
		if ($cid === false) {
			$cid = IPS_CreateCategory();
			IPS_SetParent($cid, $id);
			IPS_SetName($cid, $name);
			IPS_SetIdent($cid, $ident);
		}
		return $cid;
	}
}
