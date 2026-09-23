<?php

    /** The functional constructor for MyPseudoClass with 
     * parameters $name and $lastName. The $callStatic is
     * an override flag that if it is true it causes the 
     * static methods to be called instead of returning the
     * constructor. In this case the arguments are not used
     * to initialize the pseudo object instance but instead
     * are used to call the static methods embedded within 
     * the PsuedoClass functional constructor in the form
     * of an array.
     * */     
    function PseudoClass(array $arguments, bool $callStatic = false) {

        $PseudoClass = (function() {

            $constructor = function($arguments, $properties = null, $functions = null) {

                $getName = function($properties, $functions) {
                    $functions['randomFunction']($properties, $functions, $properties['name']);
                    //return $name;
                    return $properties['name'];
                };
                
                $getLastName = function($properties, $functions) {
                    return $properties['lastName'];
                };

                $randomFunction = function($properties, $functions, string &$input) {
                    $input[0] = mb_strtolower($input[0]);
                    $input = 'M' . $input;
                };

                if(!$properties)
                    $properties = [
                        'name' => $arguments['name'], 
                        'lastName' => $arguments['lastName']
                    ];

                if(!$functions)
                    $functions = [
                        'randomFunction' => $randomFunction,
                        'getName' => $getName,
                        'getLastName' => $getLastName
                    ];

                return [ 
                    'name' => 'property',
                    'lastName' => 'property',
                    'getName' => 'function',
                    'getLastName' => 'function',
                    'properties' => $properties,
                    'functions' => $functions
                ];

            };

            return $constructor;

        })();

        /** Proto object is the instance interface used for
         * public access it defines the names and types of 
         * pseudo class members located in the $properties 
         * and the $functions arrays respectively.  
         * */ 
        $protoObject = $PseudoClass($arguments);
        $properties = $protoObject['properties'];
        $functions = $protoObject['functions'];

        /** The invoker is used to access the public 
         * interface of the pseudoclass as well as the
         * static members. 
         * */
        $invoker = function(
            string $invocationName, 
            array $arguments = [], 
            bool $addExtension = false
            ) 
            use(&$properties, &$functions, &$protoObject) {     
            /** There are two modes the invoker can be used 
             * first is when there is no extension to add
             * which is the default in this case the 
             * function either returns a property or calls
             * a function defined in the proto object. The
             * second mode is enabled by passing true as 
             * value to $extension in this case the 
             * $invocationName arguments is the name of the
             * property or the function to be added to the
             * pseudo object. The second mode either calls
             * a function or returns a property that is in
             * the $protoObject.
             * 
             * */    

            /** Adds a new property or a function to the 
             * pseudo object (not the class) using the 
             * information passed in the $arguments 
             * variable. In this mode $arguments cannot be 
             * empty. No invocations happens in this mode 
             * therefore $invocationName is ignored.         
             * 'name' : The name of the extension to add
             * 'type' : The type of the extension to add it is
             * equal to either 'property' or 'function'
             * 'value' : The value of the extension to add if 
             * they type is 'function' then the type of value 
             * is callable.
             * 'isPublic' If true then add the extension to the
             * protoObject.
             */
            if($addExtension === true) {
                if(empty($arguments))
                    die("Arguments cannot be emtpy when adding an extension.");

                $type = $arguments['type'];

                if($type === 'property')
                    $properties[$arguments['name']] = $arguments['value'];
                else if($type === 'function')
                    $functions[$arguments['name']] = $arguments['value'];

                if($arguments['isPublic'] === true) {
                    $protoObject[$arguments['name']] = $arguments['type'];
            }

            } else {
                /** Ensure that the $invocationName is in 
                 * the $protoObject array then call it using 
                 * the $properties and $functions to provide 
                 * the context and the $arguments array as 
                 * the actual argument if it is a function 
                 * and return the value if it is a property.
                 */
                if ($protoObject[$invocationName])   
                    if ($protoObject[$invocationName] === 'function')
                        return $functions[$invocationName](
                            $properties, $functions, $arguments                        
                        );
                    else 
                        return $properties[$invocationName];
            }
        };

        /** If a static call request is received then call 
         * the static function with the given $arguments
         * array using $invoker.
        */
        if ($callStatic) {

        } else {
            return $invoker;
        }
    }

    $arguments = [
        'name' => 'Alice',
        'lastName' => 'Weidel'
    ];

    $aliceWeidel = PseudoClass($arguments);
    var_dump($aliceWeidel('getName'));    

    $extension = [
        'name' => 'email',
        'type' => 'property',
        'value' => 'alice@example.com',
        'isPublic' => true 
    ];

    $aliceWeidel('', $extension, true);

    var_dump($aliceWeidel('email'));

    $extension = [
        'name' => 'sayHello',
        'type' => 'function',
        'value' => function($properties, $functions) {
            echo "Hello World!!! from : " . $properties['lastName'] . "\n";
        },
        'isPublic' => true 
    ];

    $aliceWeidel('', $extension, true);
    $aliceWeidel('sayHello');

    $arguments = [
        'name' => 'Jason',
        'lastName' => 'Brown'
    ];

    $jasonBrown = PseudoClass($arguments);
    var_dump($jasonBrown('getName'));