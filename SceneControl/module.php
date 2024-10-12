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
        $this->RegisterPropertyInteger('ExternalInputLight', 0);

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
        // Get or create the scheduler event
        $eventID = $this->GetOrCreateSchedulerEvent();

        // Define fixed color mappings for the first 5 scenes
        $colorMap = [
            1 => 0xFFD700,  // 'Morgen' -> Gold
            2 => 0xADD8E6,  // 'Tag' -> LightBlue
            3 => 0xFFA500,  // 'Abend' -> Orange
            4 => 0x00008B,  // 'Nacht' -> DarkBlue
            5 => 0xFFFFFF   // 'Hell' -> White
        ];

        // List of additional colors for extra scenes (if more than 5 scenes)
        $extraColors = [
            0xFF6347, // Tomato
            0x40E0D0, // Turquoise
            0xEE82EE, // Violet
            0xDC143C, // Crimson
            0x7FFF00, // Chartreuse
            0x8A2BE2, // BlueViolet
            0xFF4500  // OrangeRed
        ];

        // Keep track of used colors from the extraColors list
        $usedColors = [];

        // Loop through all scenes (starting from Scene1) and update scheduler actions
        $sceneCount = $this->ReadPropertyInteger('SceneCount');
        for ($i = 1; $i <= $sceneCount; $i++) {
            $sceneIdent = "Scene$i";
            $sceneVariableID = @$this->GetIDForIdent($sceneIdent);

            if ($sceneVariableID !== false) {
                $sceneName = IPS_GetName($sceneVariableID);

                // Determine the color for the scene
                if (isset($colorMap[$i])) {
                    // Use predefined color for the first 5 scenes
                    $color = $colorMap[$i];
                } else {
                    // Assign a random, unused color for additional scenes
                    $availableColors = array_diff($extraColors, $usedColors);
                    if (!empty($availableColors)) {
                        $color = current($availableColors);
                        $usedColors[] = $color; // Mark the color as used
                    } else {
                        // If we run out of unique colors, recycle colors
                        $color = $extraColors[array_rand($extraColors)];
                    }
                }

                // Define the script to call the specific scene
                $actionScript = 'SZS_CallScene(' . $this->InstanceID . ', ' . $i . ');';

                // Update the action for the scene in the scheduler
                IPS_SetEventScheduleAction(
                    $eventID,
                    $i,                        // Action ID (matches the scene number)
                    $sceneName,                // Action name (e.g., 'Morgen', 'Tag', etc.)
                    $color,                    // Color for the action
                    $actionScript              // The script to be executed when this action is triggered
                );
            }
        }

        // Activate the event
        IPS_SetEventActive($eventID, true);
        IPS_LogMessage("SceneControl", "Scheduler colors updated.");
    }

    private function GetOrCreateSchedulerEvent()
    {
        // Get or create the scheduler event
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

        return $eventID;
    }

    public function UpdateSchedulerColors()
    {
        // Get the event ID for the scheduler
        $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

        if ($eventID === false) {
            IPS_LogMessage("SceneControl", "Scheduler event not found.");
            return; // Exit if no scheduler event exists
        }

        // Define fixed color mappings for the scenes
        $colorMap = [
            1 => 0xFFD700,  // 'Morgen' -> Gold
            2 => 0xADD8E6,  // 'Tag' -> LightBlue
            3 => 0xFFA500,  // 'Abend' -> Orange
            4 => 0x00008B,  // 'Nacht' -> DarkBlue
            5 => 0xFFFFFF   // 'Hell' -> White
        ];

        // Loop through the scenes and set colors in the scheduler
        foreach ($colorMap as $sceneNumber => $color) {
            // Define the action script to call the scene
            $actionScript = 'SZS_CallScene(' . $this->InstanceID . ', ' . $sceneNumber . ');';

            // Get the scene name based on the scene number
            $sceneVariableID = @$this->GetIDForIdent("Scene$sceneNumber");
            if ($sceneVariableID !== false) {
                $sceneName = IPS_GetName($sceneVariableID);

                // Update the scheduler event with the action and corresponding color
                IPS_SetEventScheduleAction(
                    $eventID,
                    $sceneNumber,              // Action ID (matches the scene number)
                    $sceneName,                // Action name (e.g., 'Morgen', 'Tag', etc.)
                    $color,                    // Color for the action
                    $actionScript              // The script to be executed when this action is triggered
                );
            }
        }

        // Activate the event
        IPS_SetEventActive($eventID, true);
        IPS_LogMessage("SceneControl", "Scheduler colors updated for scenes.");
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
            IPS_LogMessage("SceneControl", "Registering trigger for VariableID: " . $trigger['VariableID']);
            $triggerVariableID = $trigger['VariableID'];
            if (IPS_VariableExists($triggerVariableID)) {
                $this->RegisterMessage($triggerVariableID, VM_UPDATE); // Register for variable updates
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Debug log to see the exact flow of message calls
        IPS_LogMessage("SceneControl", "MessageSink called. SenderID: $SenderID, Message: $Message, TimeStamp: $TimeStamp");

        if ($Message == EM_CHANGECYCLIC) {
            $this->HandleSchedulerEvent($SenderID, $Data);
        } else if ($Message == VM_UPDATE) {
            $this->HandleVariableUpdate($SenderID);
        }
    }

    // Method to handle the scheduler event trigger
    private function HandleSchedulerEvent($SenderID, $Data)
    {
        $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

        if ($SenderID == $eventID) {
            $sceneNumber = $Data[0]; // The action ID corresponds to the scene number
            IPS_LogMessage("SceneControl", "Scheduler event triggered. Calling Scene " . $sceneNumber);
            $this->CallScene($sceneNumber); // Call the scene
        }
    }

    // Method to handle variable updates from triggers and IsOnId
    private function HandleVariableUpdate($SenderID)
    {
        // Check for trigger updates
        $this->CheckTriggers($SenderID);

        // Handle IsOnId (if it exists)
        $this->HandleIsOnUpdate($SenderID);
    }

    // Method to handle triggers based on SenderID
    private function CheckTriggers($SenderID)
    {
        // Retrieve triggers from properties
        $triggers = json_decode($this->ReadPropertyString('Triggers'), true);

        foreach ($triggers as $trigger) {
            if ($SenderID == $trigger['VariableID']) {
                if ($trigger['Type1'] == 2) {
                    $this->TurnOff();
                    return;
                }

                // Get both scene names from the trigger
                $newSceneName1 = $this->GetSceneNameFor($trigger['Type1'], $trigger['SceneVariableID1']);
                $newSceneName2 = isset($trigger['SceneVariableID2']) ? $this->GetSceneNameFor($trigger['Type2'], $trigger['SceneVariableID2']) : null;
                IPS_LogMessage("SceneControl", "Trigger activated for Scene Names: " . $newSceneName1 . " and " . $newSceneName2);

                // Get the currently active scene name
                $currentSceneName = $this->GetValue('ActiveScene');

                // Determine the new scene to activate based on current scene
                $sceneToActivate = $newSceneName1;
                if ($currentSceneName === $newSceneName1 && $newSceneName2 !== null) {
                    // If current scene is Scene 1 and Scene 2 is set, switch to Scene 2
                    IPS_LogMessage("SceneControl", "Switching to Scene 2.");
                    $sceneToActivate = $newSceneName2;
                }

                // Call the selected scene and turn it on
                $this->CallScene($this->GetSceneNumberFromName($sceneToActivate), true);
                return;
            }
        }
    }

    private function GetSceneNameFor($type, $sceneVariableID)
    {
        // If the type is reset (Type 1), get the scheduler's active scene
        if ($type === 1) {
            $sceneNumber = $this->GetLastActiveSceneNumberFromScheduler();
            return $this->getSceneName($sceneNumber); // Return the scheduled scene name
        }

        // Otherwise, get the name of the scene by the SceneVariableID
        return IPS_GetName($sceneVariableID);
    }

    // Method to handle SelectScene trigger type
    private function HandleSelectScene(array $trigger)
    {
        // Call the corresponding scene by the VariableID of the scene (SceneVariableID)
        $sceneVariableID = $trigger['SceneVariableID'];
        IPS_LogMessage("SceneControl", "Trigger activated for Scene Variable ID: " . $sceneVariableID);

        $sceneIdent = IPS_GetObject($sceneVariableID)['ObjectIdent'];  // Get the scene's Ident
        $this->CallScene((int)filter_var($sceneIdent, FILTER_SANITIZE_NUMBER_INT), true); // Extract scene number and call it
    }

    // Method to handle ResetScene triggers
    private function HandleResetScene()
    {
        $activeSceneNumber = $this->GetLastActiveSceneNumberFromScheduler();
        IPS_LogMessage("SceneControl", "Reset triggered, using active scene from scheduler: " . $activeSceneNumber);
        $this->CallScene($activeSceneNumber, true);
    }

    // Method to handle IsOnId updates
    private function HandleIsOnUpdate($SenderID)
    {
        $isOnId = $this->ReadPropertyInteger('IsOnId');
        if ($SenderID == $isOnId) {
            $isOnValue = GetValue($isOnId);
            IPS_LogMessage("SceneControl", "IsOnId updated. New value: " . ($isOnValue ? "true" : "false"));

            if ($isOnValue === true) {
                IPS_LogMessage("SceneControl", "IsOnId is true, calling the current scene.");
                $this->CallScene($this->GetActiveSceneNumber());
            } else {
                $this->TurnOff();
            }
        }
    }

    public function GetLastActiveSceneNumberFromScheduler()
    {
        $eventID = @IPS_GetObjectIDByIdent('SceneSchedulerEvent', $this->InstanceID);

        // Get the event data
        $eventData = IPS_GetEvent($eventID);

        // Get the current time
        $currentTime = time();
        $lastActivePoint = null;
        $lastActiveTime = 0;

        // Iterate through schedule groups
        foreach ($eventData['ScheduleGroups'] as $group) {
            foreach ($group['Points'] as $point) {
                // Convert point time to UNIX timestamp
                $pointHour = $point['Start']['Hour'];
                $pointMinute = $point['Start']['Minute'];

                // Calculate the timestamp for the point (today's date + point time)
                $pointTime = mktime($pointHour, $pointMinute, 0);

                // Check if the point has already occurred today
                if ($pointTime <= $currentTime && $pointTime > $lastActiveTime) {
                    // This is the most recent active point
                    $lastActivePoint = $point;
                    $lastActiveTime = $pointTime;
                }
            }
        }

        // If we found a last active point, return the ActionID (which is the scene number)
        if ($lastActivePoint !== null) {
            return $lastActivePoint['ActionID'];  // Return the ActionID as the scene number
        }

        // No active point was found
        IPS_LogMessage("SceneControl", "No active schedule point found.");
        return 0; // Return 0 if no active scene is found
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Value) {
            case '1': // Save the scene
                $this->SaveScene((int) filter_var($Ident, FILTER_SANITIZE_NUMBER_INT));
                break;

            case '2': // Call the scene
                $this->CallScene((int) filter_var($Ident, FILTER_SANITIZE_NUMBER_INT));
                break;

            default:
                throw new Exception('Invalid action');
        }
    }

    public function CallScene(int $SceneNumber, bool $turnOn = false)
    {
        // If TurnOn is true, trigger the ExternalInputLight
        if ($turnOn) {
            $externalInputLightID = $this->ReadPropertyInteger('ExternalInputLight');
            if ($externalInputLightID != 0 && IPS_VariableExists($externalInputLightID)) {
                RequestAction($externalInputLightID, true);
                IPS_LogMessage("SceneControl", "ExternalInputLight turned on.");
            }
        }

        // Set the Active Scene
        $this->SetValue('ActiveScene', $this->getSceneName($SceneNumber));

        // Check if the IsOnId property is set and valid
        $isOnId = $this->ReadPropertyInteger('IsOnId');

        // Only execute the scene if the IsOnId variable is true
        if ($turnOn || ($isOnId != 0 && GetValue($isOnId) === true)) {
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

    public function AddTrigger($Triggers)
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

        // Get all children recursively under the parent instance
        $allChildrenIDs = $this->GetAllChildrenRecursive($parentID);

        // Define prefixes to match
        $validPrefixes = ["Schalten", "Prozent", "Farbe", "RGB", "Wert"];

        // Get the current list of targets (VariableID array)
        $targets = json_decode($this->ReadPropertyString('Targets'), true);
        $existingVariableIDs = array_column($targets, 'VariableID'); // Extract existing VariableIDs

        // Loop through each child object
        foreach ($allChildrenIDs as $childID) {
            // Check if the direct parent has the suffix "Licht"
            $parentObject = IPS_GetObject(IPS_GetParent($childID));
            if (strpos($parentObject['ObjectName'], 'Licht') !== false) {
                // Check if the variable name starts with one of the valid prefixes
                $variableName = IPS_GetName($childID);
                foreach ($validPrefixes as $prefix) {
                    if (strpos($variableName, $prefix) === 0) {
                        // If the variable ID is not already in the list, add it
                        if (!in_array($childID, $existingVariableIDs)) {
                            $foundVariables[] = [
                                "VariableID" => $childID,
                                "GUID" => $this->generateGUID()  // Generate a GUID for the new variable
                            ];
                        }
                        break; // No need to check other prefixes once one is matched
                    }
                }
            }
        }

        // Merge the found variables into the existing targets
        $targets = array_merge($targets, $foundVariables);

        // Save the updated list of targets back to the property
        IPS_SetProperty($this->InstanceID, 'Targets', json_encode($targets));

        // Apply the changes to refresh the configuration
        IPS_ApplyChanges($this->InstanceID);

        // Log the result for debugging
        IPS_LogMessage("SceneControl", "Added " . count($foundVariables) . " variables to Targets.");
    }

    // Helper function to get all children recursively
    private function GetAllChildrenRecursive($parentID)
    {
        $allChildren = [];
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $childID) {
            $allChildren[] = $childID;
            $allChildren = array_merge($allChildren, $this->GetAllChildrenRecursive($childID)); // Recursively get sub-children
        }
        return $allChildren;
    }

    public function AutoAddScenes()
    {
        // Define the scenes with their fixed names
        $scenes = [
            1 => 'Morgen',
            2 => 'Tag',
            3 => 'Abend',
            4 => 'Nacht',
            5 => 'Hell'
        ];

        foreach ($scenes as $sceneNumber => $sceneName) {
            // Construct the ident for the scene (Scene1, Scene2, etc.)
            $ident = "Scene" . $sceneNumber;

            // Check if the scene variable already exists
            $sceneVariableID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

            if ($sceneVariableID !== false) {
                // If the scene exists, rename it to the new value
                IPS_SetName($sceneVariableID, $sceneName);
                IPS_LogMessage("SceneControl", "Renamed scene $sceneNumber to '$sceneName'.");
            } else {
                // If the scene doesn't exist, create it
                $sceneVariableID = $this->RegisterVariableInteger($ident, $sceneName, 'SZS.SceneControl');
                $this->EnableAction($ident);
                SetValue($sceneVariableID, 2); // Set the default value to "Call" for the new scene
                IPS_LogMessage("SceneControl", "Created new scene $sceneNumber with name '$sceneName'.");
            }
        }

        // Apply changes after adding or renaming the scenes
        $this->UpdateSchedulerColors();
        IPS_ApplyChanges($this->InstanceID);
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
        $externalInputLightID = $this->ReadPropertyInteger('ExternalInputLight');
        if ($externalInputLightID != 0 && IPS_VariableExists($externalInputLightID)) {
            RequestAction($externalInputLightID, false);
            IPS_LogMessage("SceneControl", "ExternalInputLight turned off.");
        }

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
