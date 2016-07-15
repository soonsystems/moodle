<?php 

function local_uniulm_initsettings(){
    global $CFG, $DB, $USER, $PAGE;
    
    // Script is ran the first time the admin is on the frontpage (personal or not)
    if($USER->id !==1 || $PAGE->course->id != 1 || (strpos($PAGE->url,'/admin/') !== false)){
        return '';
    }
    
    // Check if this script has already run
    $uniulm_intsettings_run = get_config('local_uniulm_initsettings', 'initrun');
    
    $error_occurred = false;
    $error_msg = '';
    
    $success_msg = '';
    
    if(!empty($uniulm_intsettings_run)){
        return '';
    } else {
        
        $initsettings_plugins = (isset($CFG->local_uniulm_initsettings_plugins) ? $CFG->local_uniulm_initsettings_plugins : null);
        $initsettings_tasks = (isset($CFG->local_uniulm_initsettings_tasks) ? $CFG->local_uniulm_initsettings_tasks : null);
        $initsettings_activities = (isset($CFG->local_uniulm_initsettings_activities) ? $CFG->local_uniulm_initsettings_activities : null);
        
        //verarbeite Plugin-Einstellungen
        if(!empty($initsettings_plugins) && is_array($initsettings_plugins)){
            
            foreach ($initsettings_plugins as $pluginname => $plugin_settings) {
                if(is_array($plugin_settings)){
                    foreach ($plugin_settings as $name => $value) {
                        try{
                            $plugin_entry = $DB->get_record('config_plugins', array('plugin'=>$pluginname, 'name'=>$name));
                            if(!empty($plugin_entry)){
                                $plugin_entry->value = $value;
                                $updated = $DB->update_record('config_plugins', $plugin_entry);
                            }else{
                                $plugin_entry = new stdClass();
                                $plugin_entry->plugin = $pluginname;
                                $plugin_entry->name = $name;
                                $plugin_entry->value = $value;
                                $insert_id = $DB->insert_record('config_plugins', $plugin_entry, true);
                            }
                            $success_msg .= "processed successfully: $pluginname: $name -> $value"."\n";
                        }catch(dml_exception $ex){
                            $log_msg = __FUNCTION__." => error for $pluginname: $name -> $value ".$ex->getMessage();
                            $error_msg .= $log_msg."\n";
                            $error_occurred = true;
                            continue;
                        }
                    }
                }
            }
        }

        // Deactivate Tasks
        if(!empty($initsettings_tasks) && is_array($initsettings_tasks)){
            $success_msg .= "\n";
            
            foreach ($initsettings_tasks as $action => $tasks) {
                if($action == 'disable' && is_array($tasks)){
                    foreach ($tasks as $taskname) {
                        if ($taskname) {
                            try {
                                $task = \core\task\manager::get_scheduled_task($taskname);
                                if (!$task) {
                                    $log_msg = "uni ulm task settings => task does not exist: $taskname";
                                    $error_msg .= $log_msg."\n";
                                    $error_occurred = true;
                                    continue;
                                }
                                
                                $task->set_disabled(true);
                                $task->set_customised(true);
    
                                \core\task\manager::configure_scheduled_task($task);
                                
                            } catch (dml_exception $ex) {
                                $log_msg = "uni ulm task settings => error for $taskname: ".$ex->getMessage();
                                $error_msg .= $log_msg."\n";
                                $error_occurred = true;
                                continue;
                            }

                            $success_msg .= "processed successfully: $taskname -> set_disabled(true) / set_customised(true)"."\n";
                        }
                    }
                }
            }
        }
        
        // Activate / deactivate activities
        if(!empty($initsettings_activities) && is_array($initsettings_activities)){

            $success_msg .= "\n";
            
            foreach ($initsettings_activities as $action => $activities) {
                if($action == 'hide'){
                    if (is_array($activities)) {
                        foreach ($activities as $key => $activity) {
                            try{
                                if (!$module = $DB->get_record("modules", array("name"=>$activity))) {
                                    $log_msg = "uni ulm activity settings => module does not exist: $activity";
                                    $error_msg .= $log_msg."\n";
                                    $error_occurred = true;
                                    continue;
                                }
                                
                                //module is already NOT visible --> continue to prevent "visible old" to be overwritten by false value!
                                if($module->visible == 0){
                                    error_log("$activity visible == 0 --> continue");
                                    continue;
                                }
                                // Hide main module
                                $DB->set_field("modules", "visible", "0", array("id"=>$module->id));
                                
                                // Remember the visibility status in visibleold and hide...
                                $sql = "UPDATE {course_modules} SET visibleold=visible, visible=0 WHERE module=?";
                                $updated = $DB->execute($sql, array($module->id));
                                
                                $success_msg .= "processed successfully: $activity -> visible = 0"."\n";
                                
                                // Increment course.cacherev for courses where we just made something invisible.
                                // This will force cache rebuilding on the next request.
                                increment_revision_number('course', 'cacherev', "id IN (SELECT DISTINCT course FROM {course_modules} WHERE visibleold=1 AND module=?)",
                                        array($module->id));
                                core_plugin_manager::reset_caches();
                                
                            }catch (dml_exception $ex) {
                                $log_msg = "uni ulm activity settings => error for $activity: ".$ex->getMessage();
                                $error_msg .= $log_msg."\n";
                                $error_occurred = true;
                                continue;
                            }
                        }
                    }
                }
            }
        }
        
        if(empty($error_occurred)){
            set_config('initrun', 1, 'local_uniulm_initsettings');
        }
    }
}