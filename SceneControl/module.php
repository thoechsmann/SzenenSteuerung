<?php

declare(strict_types=1);
include_once __DIR__ . '/attributes.php';
class SceneControl extends IPSModule
{
    use Attributes;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyInteger('SceneCount', 5);
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyString('Triggers', '[]');
        $this->RegisterPropertyInteger('IsOnId', 0);

        //Attributes
        $this->RegisterAttributeString('SceneData', '[]');


        $this->RegisterVariableString('ActiveScene', $this->Translate('Active Scene'), '', -1);
        if (!IPS_VariableProfileExists('SZS.SceneControl')) {
            IPS_CreateVariableProfile('SZS.SceneControl', 1);
            IPS_SetVariableProfileValues('SZS.SceneControl', 1, 2, 0);
            //IPS_SetVariableProfileIcon("SZS.SceneControl", "");
            IPS_SetVariableProfileAssociation('SZS.SceneControl', 1, $this->Translate('Save'), '', -1);
            IPS_SetVariableProfileAssociation('SZS.SceneControl', 2, $this->Translate('Call'), '', -1);
        }
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function CreateSchedulerEvent()
    {
        $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

        if ($eventID === false) {
            // Create the event if it doesn't exist
            $eventID = IPS_CreateEvent(2);
            IPS_SetParent($eventID, $this->InstanceID); // Attach the event to the current instance
            IPS_SetIdent($eventID, 'SceneSchedulerEvent');
            IPS_SetName($eventID, 'Scene Scheduler');
            IPS_SetEventScheduleGroup($eventID, 0, 31); //Mo - Fr (1 + 2 + 4 + 8 + 16)
            IPS_SetEventScheduleGroup($eventID, 1, 96); //Sa + Su (32 + 64)

        }

        // Define color mappings for specific scene names
        $colorMap = [
            "Morgen" => 0xFFFACD,  // Morning (Morgen) -> LemonChiffon (light yellow)
            "Tag" => 0xADD8E6,     // Day (Tag) -> LightBlue
            "Abend" => 0xFFD700,   // Evening (Abend) -> Gold
            "Nacht" => 0x00008B,   // Night (Nacht) -> DarkBlue
            "Hell" => 0xFFFFFF     // Bright (Hell) -> White
        ];

        // Define a list of generic colors to use for other scenes if needed
        $genericColorList = [
            0xFF6347, // Tomato
            0xFFA500, // Orange
            0xADFF2F, // GreenYellow
            0x40E0D0, // Turquoise
            0xEE82EE, // Violet
            0xFF1493, // DeepPink
            0xDC143C, // Crimson
            0x7FFF00  // Chartreuse
        ];

        // Add actions for each scene in the scheduler
        $sceneCount = $this->ReadPropertyInteger('SceneCount');
        $usedColors = []; // Track used colors for generic scenes

        for ($i = 1; $i <= $sceneCount; $i++) {
            $sceneVariableID = @$this->GetIDForIdent("Scene$i"); // Get the variable ID for the scene
            $sceneName = IPS_GetName($sceneVariableID); // Get the scene name from the variable ID

            // Determine the color for the action
            if (isset($colorMap[$sceneName])) {
                // Use predefined color for specific names in colorMap
                $color = $colorMap[$sceneName];
            } else {
                // Cycle through remaining colors in the genericColorList
                $availableColors = array_diff($genericColorList, $usedColors); // Exclude already used colors
                $color = current($availableColors) !== false ? current($availableColors) : $genericColorList[0]; // Cycle through colors
                $usedColors[] = $color; // Mark this color as used
            }

            // Define the script to call the specific scene
            $actionScript = 'SZS_CallScene(' . $this->InstanceID . ', ' . $i . ');';

            // Create an action for each scene
            IPS_SetEventScheduleAction(
                $eventID,
                $i,                        // Action ID (Unique for each action)
                $sceneName,                // Action name (e.g., 'Aus', 'Tag', 'Nacht', or Scene Name)
                $color,                    // Color for the action in the scheduler UI
                $actionScript              // The script to be executed when this action is triggered
            );
        }

        // Activate the event
        IPS_SetEventActive($eventID, true);
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->CreateSchedulerEvent();

        $targets = json_decode($this->ReadPropertyString('Targets'), true);

        //Transfer data from Target Category(legacy) to recent List
        if ($targets == []) {
            $targetCategoryID = @$this->GetIDForIdent('Targets');

            if ($targetCategoryID) {
                foreach (IPS_GetChildrenIDs($targetCategoryID) as $childID) {
                    $targetID = IPS_GetLink($childID)['TargetID'];
                    $line = [
                        'VariableID' => $targetID
                    ];
                    array_push($targets, $line);
                    IPS_DeleteLink($childID);
                }

                IPS_DeleteCategory($targetCategoryID);
                $needsReload = true;
            }
        }

        //Add GUID if none set
        $needsReload = false;
        foreach ($targets as $index => $target) {
            if (!isset($targets[$index]['GUID'])) {
                $targets[$index]['GUID'] = $this->generateGUID();
                $needsReload = true;
            }
        }

        //Create Scene variables
        $sceneCount = $this->ReadPropertyInteger('SceneCount');
        for ($i = 1; $i <= $sceneCount; $i++) {
            $variableID = $this->RegisterVariableInteger('Scene' . $i, sprintf($this->Translate('Scene %d'), $i), 'SZS.SceneControl');
            $this->EnableAction('Scene' . $i);
            SetValue($variableID, 2);
        }

        $sceneData = json_decode($this->ReadAttributeString('SceneData'));

        //If older versions contain errors regarding SceneData SceneControl would become unusable otherwise, even in fixed versions
        if (!is_array($sceneData)) {
            $sceneData = [];
        }

        //Preparing SceneData for later use
        $sceneCount = $this->ReadPropertyInteger('SceneCount');

        for ($i = 1; $i <= $sceneCount; $i++) {
            if (!array_key_exists($i - 1, $sceneData)) {
                $sceneData[$i - 1] = new stdClass();
            }
        }

        //Getting data from legacy SceneData to put them in SceneData attribute (including wddx, JSON)
        for ($i = 1; $i <= $sceneCount; $i++) {
            $sceneDataID = @$this->GetIDForIdent('Scene' . $i . 'Data');
            if ($sceneDataID) {
                $decodedSceneData = null;
                if (function_exists('wddx_deserialize')) {
                    $decodedSceneData = wddx_deserialize(GetValue($sceneDataID));
                }

                if ($decodedSceneData == null) {
                    $decodedSceneData = json_decode(GetValue($sceneDataID));
                }

                if ($decodedSceneData) {
                    $sceneData[$i - 1] = $decodedSceneData;
                }
                $this->UnregisterVariable('Scene' . $i . 'Data');
            }
        }

        //Deleting surplus data in SceneData
        $sceneData = array_slice($sceneData, 0, $sceneCount);
        $this->WriteAttributeString('SceneData', json_encode($sceneData));

        //Deleting surplus variables
        for ($i = $sceneCount + 1;; $i++) {
            if (@$this->GetIDForIdent('Scene' . $i)) {
                $this->UnregisterVariable('Scene' . $i);
            } else {
                break;
            }
        }

        //Transfer variableIDs to IDs
        $variableGUIDs = [];
        foreach ($targets as $target) {
            $variableGUIDs[$target['VariableID']] = $target['GUID'];
        }
        $scenes = json_decode($this->ReadAttributeString('SceneData'), true);
        foreach ($scenes as $index => $scene) {
            foreach ($scene as $variableID => $value) {
                if (array_key_exists($variableID, $variableGUIDs)) {
                    unset($scenes[$index][$variableID]);
                    $scenes[$index][$variableGUIDs[$variableID]] = $value;
                }
            }
        }
        $this->WriteAttributeString('SceneData', json_encode($scenes));

        //Reload if there were any changes
        if ($needsReload) {
            IPS_SetProperty($this->InstanceID, 'Targets', json_encode($targets));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        //Add references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        foreach ($targets as $target) {
            $this->RegisterReference($target['VariableID']);
        }

        //Unregister all messages
        $messageList = array_keys($this->GetMessageList());
        foreach ($messageList as $message) {
            $this->UnregisterMessage($message, VM_UPDATE);
        }

        $isOnId = $this->ReadPropertyInteger('IsOnId');
        if ($isOnId != 0) {
            $this->RegisterMessage($isOnId, VM_UPDATE);
        }

        // Register the triggers for message updates
        $triggers = json_decode($this->ReadPropertyString('Triggers'), true);

        foreach ($triggers as $trigger) {
            $triggerVariableID = $trigger['VariableID'];
            if (IPS_VariableExists($triggerVariableID)) {
                $this->RegisterMessage($triggerVariableID, VM_UPDATE); // Register for variable updates
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == EM_CHANGECYCLIC) {
            // Handle the scheduler event trigger
            $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

            if ($SenderID == $eventID) {
                $sceneNumber = $Data[0]; // The action ID corresponds to the scene number
                IPS_LogMessage("SceneControl", "Scheduler event triggered. Calling Scene " . $sceneNumber);
                $this->CallScene($sceneNumber); // Call the scene
                return;
            }
        } else if ($Message == VM_UPDATE) {
            // Check for trigger updates
            $triggers = json_decode($this->ReadPropertyString('Triggers'), true);

            // Loop through the triggers to find if the SenderID matches any trigger's VariableID
            foreach ($triggers as $trigger) {
                if ($SenderID == $trigger['VariableID']) {
                    // Get the current value of the trigger variable
                    $triggerValue = GetValue($SenderID);

                    // If SceneVariableID is empty, use the active scene from the scheduler
                    if (empty($trigger['SceneVariableID'])) {
                        $activeSceneNumber = $this->GetActiveSceneFromScheduler();
                        IPS_LogMessage("SceneControl", "Trigger activated but no SceneVariableID, using active scene from scheduler: " . $activeSceneNumber);
                        $this->CallScene($activeSceneNumber);
                    } else {
                        // If the trigger value is true, call the corresponding scene by its Scene VariableID
                        $sceneVariableID = $trigger['SceneVariableID'];
                        IPS_LogMessage("SceneControl", "Trigger activated for Scene Variable ID: " . $sceneVariableID);

                        // Call the corresponding scene by the VariableID of the scene (SceneVariableID)
                        $sceneIdent = IPS_GetObject($sceneVariableID)['ObjectIdent'];  // Get the scene's Ident
                        $this->CallScene((int) filter_var($sceneIdent, FILTER_SANITIZE_NUMBER_INT)); // Extract scene number and call it
                    }

                    // Once handled, exit the function early
                    return;
                }
            }

            // Handle IsOnId (if it exists)
            $isOnId = $this->ReadPropertyInteger('IsOnId');
            if ($SenderID == $isOnId) {
                $isOnValue = GetValue($isOnId);
                IPS_LogMessage("SceneControl", "IsOnId updated. New value: " . ($isOnValue ? "true" : "false"));

                // If IsOnId is true, call the active scene
                if ($isOnValue === true) {
                    IPS_LogMessage("SceneControl", "IsOnId is true, calling the current scene.");
                    $this->CallScene($this->GetActiveSceneNumber());
                } else {
                    $this->TurnOff();
                }

                // Once handled, exit the function early
                return;
            }

            // If no triggers matched, log that the trigger was not found
            IPS_LogMessage("SceneControl", "Trigger not found for SenderID: " . $SenderID);
        }
    }

    public function GetActiveSceneFromScheduler()
    {
        // Retrieve the event ID for the scheduler
        $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

        if ($eventID !== false) {
            // Get the event schedule information
            $eventData = IPS_GetEvent($eventID);

            // Go through the event actions to determine the active action
            foreach ($eventData['ScheduleActions'] as $action) {
                if ($action['Enabled']) {
                    // Return the action ID, which corresponds to the scene number
                    return $action['ID'];
                }
            }
        }

        // Log if no active scene was found
        IPS_LogMessage("SceneControl", "No active scene found in the scheduler.");
        return 0; // Return 0 if no active scene is found
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Value) {
            case '1': // Save the scene
                $this->SetValue('ActiveScene', IPS_GetName($this->GetIDForIdent($Ident)));
                $this->SaveValues($Ident);
                break;

            case '2': // Call the scene
                $this->CallScene((int) filter_var($Ident, FILTER_SANITIZE_NUMBER_INT));
                // $this->SetValue('ActiveScene', IPS_GetName($this->GetIDForIdent($Ident)));

                // // Check if the IsOnId property is set and valid
                // $isOnId = $this->ReadPropertyInteger('IsOnId');

                // // Only execute the scene if the IsOnId variable is true
                // if ($isOnId != 0 && GetValue($isOnId) === true) {
                //     $this->CallValues($Ident);
                // } else {
                //     IPS_LogMessage("SceneControl", "Scene call skipped: IsOnId is not active (false) or not set.");
                // }
                break;

            default:
                throw new Exception('Invalid action');
        }
    }

    public function CallScene(int $SceneNumber)
    {
        $this->SetValue('ActiveScene', $this->getSceneName($SceneNumber));

        // Check if the IsOnId property is set and valid
        $isOnId = $this->ReadPropertyInteger('IsOnId');

        // Only execute the scene if the IsOnId variable is true
        if ($isOnId != 0 && GetValue($isOnId) === true) {
            $this->CallValues("Scene$SceneNumber");
        }
    }

    public function SaveScene(int $SceneNumber)
    {
        $this->SaveValues("Scene$SceneNumber");
        $this->SetValue('ActiveScene', $this->getSceneName($SceneNumber));
    }

    public function GetSceneNumberFromName(string $sceneName)
    {
        // Retrieve all scene variables (Scene1, Scene2, etc.)
        $childrenIDs = IPS_GetChildrenIDs($this->InstanceID);

        // Loop through each child variable to find the matching scene name
        foreach ($childrenIDs as $childID) {
            $childName = IPS_GetName($childID);
            if ($sceneName === $childName) {
                // Extract the number from the child object's Ident (e.g., 'Scene1' -> 1)
                $ident = IPS_GetObject($childID)['ObjectIdent'];
                $sceneNumber = (int) filter_var($ident, FILTER_SANITIZE_NUMBER_INT);
                return $sceneNumber;
            }
        }

        // Log if no matching scene was found
        IPS_LogMessage("SceneControl", "No matching scene found for name: " . $sceneName);
        return 0;
    }

    public function GetActiveSceneNumber()
    {
        $sceneName = $this->GetValue('ActiveScene');
        return $this->GetSceneNumberFromName($sceneName);
    }

    public function AddVariable(string $Targets)
    {
        $this->SendDebug('New Value', json_encode($Targets), 0);
        $form = json_decode($this->GetConfigurationForm(), true);
        $this->UpdateFormField('Targets', 'columns', json_encode($form['elements'][1]['columns']));
    }

    public function AddTrigger(string $Triggers)
    {
        $this->SendDebug('New Value', json_encode($Triggers), 0);
        $form = json_decode($this->GetConfigurationForm(), true);
        $this->UpdateFormField('Triggers', 'columns', json_encode($form['elements'][1]['columns']));
    }

    public function AutoAddLights()
    {
        // Get the parent instance of the current instance
        $parentID = IPS_GetParent($this->InstanceID);

        // Initialize an array to store found variables
        $foundVariables = [];

        // Recursive function to find matching variables in all child levels
        $this->findMatchingVariablesRecursive($parentID, $foundVariables);

        // Get the current list of targets (VariableID array)
        $targets = json_decode($this->ReadPropertyString('Targets'), true);

        // Merge the found variables into the existing targets
        $targets = array_merge($targets, $foundVariables);

        // Save the updated list of targets back to the property
        IPS_SetProperty($this->InstanceID, 'Targets', json_encode($targets));

        // Apply the changes to refresh the configuration
        IPS_ApplyChanges($this->InstanceID);
    }

    // Recursive function to find variables matching the criteria
    private function findMatchingVariablesRecursive($parentID, &$foundVariables)
    {
        // Get all children of the current parent
        $childrenIDs = IPS_GetChildrenIDs($parentID);

        // Loop through each child
        foreach ($childrenIDs as $childID) {
            // Check if the direct parent has the suffix "Licht"
            $parentObject = IPS_GetObject(IPS_GetParent($childID));
            $parentName = $parentObject['ObjectName'];

            if (strpos($parentName, 'Licht') !== false) {
                // Check if the variable name is "Farbe", "Schalten", or "Prozent"
                $variableName = IPS_GetName($childID);

                if (in_array($variableName, ["Farbe", "Schalten", "Prozent"])) {
                    // Add the variable ID to the foundVariables array
                    $foundVariables[] = [
                        "VariableID" => $childID,
                        "GUID" => $this->generateGUID()  // Generate a GUID for the new variable
                    ];
                }
            }

            // Check if the child is a category or instance to search recursively
            if (IPS_GetObject($childID)['ObjectType'] == 0 || IPS_GetObject($childID)['ObjectType'] == 1) {
                $this->findMatchingVariablesRecursive($childID, $foundVariables);  // Recursive call for deeper levels
            }
        }
    }

    public function AutoAddScenes()
    {
        // Define the scenes with their respective numbers
        $scenesToAdd = [
            1 => 'Morgen',
            2 => 'Tag',
            3 => 'Abend',
            4 => 'Nacht',
            5 => 'Hell'
        ];

        // Log the start of the process
        IPS_LogMessage("SceneControl", "Starting AutoAddScenes process...");

        // Loop through each scene and add it if it doesn't exist
        foreach ($scenesToAdd as $sceneNumber => $sceneName) {
            $sceneIdent = "Scene" . $sceneNumber;

            // Check if the scene already exists
            if (!@$this->GetIDForIdent($sceneIdent)) {
                // Register the scene variable if it doesn't exist
                $variableID = $this->RegisterVariableInteger($sceneIdent, $sceneName, 'SZS.SceneControl');
                $this->EnableAction($sceneIdent);
                SetValue($variableID, 2); // Default value, can be adjusted as needed

                // Log the addition of the scene
                IPS_LogMessage("SceneControl", "Added scene: " . $sceneName . " with ID: " . $variableID);
            } else {
                // Log that the scene already exists
                IPS_LogMessage("SceneControl", "Scene '" . $sceneName . "' already exists.");
            }
        }

        // Log the end of the process
        IPS_LogMessage("SceneControl", "AutoAddScenes process completed.");
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['elements'][1]['columns'][0]['add'] = $this->generateGUID();
        return json_encode($form);
    }

    private function generateGUID()
    {
        return sprintf('{%04X%04X-%04X-%04X-%04X-%04X%04X%04X}', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    private function getVariable($guid)
    {
        $targets = json_decode($this->ReadPropertyString('Targets'), true);
        foreach ($targets as $target) {
            if ($target['GUID'] == $guid) {
                return $target['VariableID'];
            }
        }
        return 0;
    }

    private function getSceneName(int $sceneID)
    {
        if ($sceneID != 0) {
            return IPS_GetName($this->GetIDForIdent("Scene$sceneID"));
        } else {
            return $this->Translate('Unknown');
        }
    }

    private function SaveValues(string $sceneIdent)
    {
        IPS_LogMessage("SceneControl", "SaveValues: " . $sceneIdent);
        $data = [];

        $targets = json_decode($this->ReadPropertyString('Targets'), true);

        foreach ($targets as $target) {
            $VarID = $target['VariableID'];
            if (!IPS_VariableExists($VarID)) {
                continue;
            }
            $data[$target['GUID']] = GetValue($VarID);
        }

        $sceneData = json_decode($this->ReadAttributeString('SceneData'));

        $i = (int) filter_var($sceneIdent, FILTER_SANITIZE_NUMBER_INT);

        $sceneData[$i - 1] = $data;

        $this->WriteAttributeString('SceneData', json_encode($sceneData));
    }

    private function CallValues(string $sceneIdent)
    {
        $sceneData = json_decode($this->ReadAttributeString('SceneData'), true);
        IPS_LogMessage("SceneControl", "SceneData: " . $this->ReadAttributeString('SceneData'));

        $i = (int) filter_var($sceneIdent, FILTER_SANITIZE_NUMBER_INT);
        IPS_LogMessage("SceneControl", "i: " . $i);

        $data = $sceneData[$i - 1];
        // IPS_LogMessage("SceneControl", "date: " . $data);

        if (count($data) > 0) {
            foreach ($data as $guid => $value) {
                $id = $this->getVariable($guid);
                if (IPS_VariableExists($id)) {
                    $v = IPS_GetVariable($id);
                    if (GetValue($id) == $value) {
                        continue;
                    }
                    if ($v['VariableCustomAction'] > 0) {
                        $actionID = $v['VariableCustomAction'];
                    } else {
                        $actionID = $v['VariableAction'];
                    }
                    //Skip this device if we do not have a proper id
                    if ($actionID < 10000) {
                        continue;
                    }

                    RequestAction($id, $value);
                }
            }
        } else {
            echo $this->Translate('No saved data for this Scene');
        }
    }

    public function TurnOff()
    {
        $targets = json_decode($this->ReadPropertyString('Targets'), true);
        foreach ($targets as $target) {
            $id = $target['VariableID'];
            if (IPS_VariableExists($id)) {
                switch (IPS_GetVariable($id)['VariableType']) {
                    case 0: // Boolean
                        RequestAction($id, false);
                        break;
                    case 1: // Integer
                        RequestAction($id, 0);
                        break;
                    case 2: // Float
                        RequestAction($id, 0.0);
                        break;
                    case 3: // String
                        break;
                    default:
                }
            }
        }
    }
}
