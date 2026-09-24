<?php

    $PseudoClass = function($arguments, bool $callStatic = false) {

        /** The static context of the pseudo class. This is
        * used to keep the members that are set up
        * independently of the process involving the set
        * up of the instance context. This context is
        * passed to the pseudo constructor closure so that
        * the instance context can interact with the
        * static context but not vice-versa in other words
        * the static context cannot interact with the 
        * instance context.
        */
        static $static = [
            'properties' => [
                'userId' => '1' 
            ],
            'functions' => [
                'getUserId' => function($static) {
                    return $static['properties']['userId']++;
                },
                'getClassName' => function($static) {
                    return "PseudoClass";
                }
            ]
        ];

        // Public interface of the static context
        static $staticObject = [
            'getClassName' => 'function'
        ];

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
        $MetaClass = function (array $arguments, bool $callStatic = false) use(&$static, &$staticObject) {

            $PseudoConstructor = (function() {

                $constructor = function(&$instance, $static, $arguments) {

                    $getName = function($instance, $static) {
                        $m_randomFunction = $instance['functions']['randomFunction'];
                        $m_name = $instance['properties']['name'];
                        $m_randomFunction($m_name);
                        //return $name;
                        return $m_name;
                    };

                    $setName = function($instance, $static, $arguments) {
                        $name = $arguments['name'];
                        $instance['properties']['name'] = $name;
                    };
                    
                    $getUserId = function($instance, $static) {
                        $userId = $instance['properties']['userId'];
                        return $userId;
                    };

                    $getLastName = function($instance, $static) {
                        $lastName = $instance['properties']['lastName'];
                        return $lastName;
                    };

                    $randomFunction = function(&$input) use($instance, $static) {
                        $input[0] = mb_strtolower($input[0]);
                        $input = 'M' . $input;
                    };

                    /** Populate the $instance array entries 
                     * with properties and functions.
                    */
                    if(!$instance)
                        $instance = [
                            'properties' => [
                                'name' => $arguments['name'], 
                                'lastName' => $arguments['lastName'],
                                'userId' => (function() use($static) {
                                    return $static['functions']['getUserId']($static);
                                })()
                            ],
                            'functions' => [
                                'randomFunction' => $randomFunction,
                                'getName' => $getName,
                                'getLastName' => $getLastName
                            ]
                        ];

                    /** Proto object public interface. */
                    return [ 
                        'name' => 'property',
                        'lastName' => 'property',
                        'getName' => 'function',
                        'getLastName' => 'function',
                        'getUserId' => 'function',
                        'setName' => 'function'             
                    ];

                };

                return $constructor;

            })();

            /** Proto object is the instance interface used for
             * public access it defines the names and types of 
             * pseudo class members located in the $properties 
             * and the $functions arrays respectively.  
             * */ 
            $instance = [];

            /** Do not go through the initialization process 
             * if a static call is requested.
             */
            if (!$callStatic)
                $protoObject = $PseudoConstructor($instance, $static, $arguments);        

            /** The invoker is used to access the public 
             * interface of the pseudoclass as well as the
             * static members. 
             * */
            $invoker = function(
                string $invocationName, 
                array $arguments = [],     
                bool $isStatic = false,        
                bool $addExtension = false            
                ) 
                use(&$instance, &$protoObject, &$static, &$staticObject) {     
                /** There are two modes the invoker can be used 
                 * in the first is when there is no extension 
                 * to add which is the default in this case the 
                 * function either returns a property or calls
                 * a function defined in the proto or the static 
                 * object that are used for public access. 
                 * 
                 * The second mode is enabled by passing true 
                 * as value to $addExtension in this case the 
                 * $invocationName argument is the name of the
                 * property or the function to be added to the
                 * pseudo or the object and the $arguments 
                 * input is structured as follows
                 * 
                 * */    

                /** $arguments when $addExtension = true is 
                 * passed
                 * 
                 * string 'type' : 'function|property' this is
                 * type of the member to be added.
                 * 
                 * mixed|callable 'value' : the value of the 
                 * property or the function to be added.
                 *  
                 * bool 'isPublic' : indicates whether the 
                 * member to be added is public or private.
                 */            
                if ($addExtension === true) {
                    if (empty($arguments))
                        die("Arguments cannot be emtpy when adding an extension.");

                    /** Unfurl the arguments. */
                    $type = $arguments['type'];
                    $value = $arguments['value'];
                    $isPublic = $arguments['isPublic'];

                    if ($type === 'property')
                        /** Check whether isStatic is set. */
                        if ($isStatic)             
                            $static['properties'][$invocationName] = $value;
                        else 
                            $instance['properties'][$invocationName] = $value;
                    else if($type === 'function')
                        /** Check whether isStatic is set. */
                        if ($isStatic)
                            $static['functions'][$invocationName] = $value;
                        else 
                            $instance['functions'][$invocationName] = $value;                    

                    if ($isPublic === true) 
                        /** Check whether isStatic is set. */
                        if ($isStatic)
                            $staticObject[$invocationName] = $arguments['type'];
                        else 
                            $protoObject[$invocationName] = $arguments['type'];
                    
                /** Add extension is false. */
                } else {
                    /** Non-static calls. */
                    if(!$isStatic) {
                        /** Ensure that the $invocationName is in 
                         * the $protoObject array then take action 
                         * based on whether it is a function or a 
                         * property.
                         * 
                         * The $instance array provides the context
                         * and the $arguments array contains the 
                         * actual arguments to be supplied for a 
                         * function to be called.
                         */
                        if ($protoObject[$invocationName])   
                            if ($protoObject[$invocationName] === 'function')
                                return $instance['functions'][$invocationName](
                                    $instance, $static, $arguments                        
                                );
                            else 
                                return $instance['properties'][$invocationName];

                    /** Static calls. */
                    } else {
                        /** Check whether the $invocationName 
                         * is defined in $staticObject then 
                         * either call the corresponding 
                         * function or return the property.
                         */
                        if($staticObject[$invocationName]) 
                            if($staticObject[$invocationName] === 'function') 
                                return $static['functions'][$invocationName](
                                    $static, $arguments
                                );
                            else 
                                return $static['properties'][$invocationName];                    
                    }
                    
                }
            };

            /** If a static call request is received then call 
             * the static function with the given $arguments
             * array using $invoker.
            */
            if ($callStatic) {
                $invocationName = $arguments['invocationName'];
                $staticArguments = $arguments['staticArguments'];
                return $invoker($invocationName, $staticArguments, true);
            } else {
                return $invoker;
            }
        };

        return $MetaClass($arguments, $callStatic);

    };

    /** Create a new instance. */
    $arguments = [
        'name' => 'Alice',
        'lastName' => 'Weidel'
    ];

    $aliceWeidel = $PseudoClass($arguments);
    echo "{$aliceWeidel('getName')}\n";    

    /** Add an instance property. */
    $invocationName = 'email';
    
    $arguments = [
        'type' => 'property',
        'value' => 'alice@example.com',
        'isPublic' => true
    ];

    $aliceWeidel($invocationName, $arguments, false, true);

    /** Display the added instance property. */
    echo "{$aliceWeidel('email')}\n";

    /** Add an instance function. */
    $invocationName = 'sayHello';
    $arguments = [        
        'type' => 'function',
        'value' => function($instance, $static) {
            echo "Hello World!!! from : " . $instance['functions']['getLastName']($instance, $static) . "\n";
        },
        'isPublic' => true 
    ];

    /** Call the added instance function. */
    $aliceWeidel($invocationName, $arguments, false, true);
    echo "{$aliceWeidel('sayHello')}";

    /** Create a new instance. */
    $arguments = [
        'name' => 'Jason',
        'lastName' => 'Brown'
    ];

    $jasonBrown = $PseudoClass($arguments);

    /** Call the added instance function. */
    echo "{$jasonBrown('getName')}\n";

    /** Call static function. */
    $arguments = [
        'invocationName' => 'getClassName',
        'staticArguments' => []
    ];
    echo "{$PseudoClass($arguments, true)}\n";

    /** Add new static function extension. */
    $invocationName = 'sayHelloStatic';
    $arguments = [        
        'type' => 'function',
        'value' => function($static) {
            echo "Hello World!!! from static \n";
        },
        'isPublic' => true 
    ];

    $aliceWeidel($invocationName, $arguments, true, true);

    /** Call the added static function. */
    $arguments = [
        'invocationName' => 'sayHelloStatic',
        'staticArguments' => []
    ];
    echo "{$PseudoClass($arguments, true)}\n";


