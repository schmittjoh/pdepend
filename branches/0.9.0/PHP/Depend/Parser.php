<?php
/**
 * This file is part of PHP_Depend.
 *
 * PHP Version 5
 *
 * Copyright (c) 2008-2009, Manuel Pichler <mapi@pdepend.org>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Manuel Pichler nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category  QualityAssurance
 * @package   PHP_Depend
 * @author    Manuel Pichler <mapi@pdepend.org>
 * @copyright 2008-2009 Manuel Pichler. All rights reserved.
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   SVN: $Id: Parser.php 675 2009-03-05 07:40:28Z mapi $
 * @link      http://pdepend.org/
 */

require_once 'PHP/Depend/ConstantsI.php';
require_once 'PHP/Depend/BuilderI.php';
require_once 'PHP/Depend/TokenizerI.php';
require_once 'PHP/Depend/Code/Value.php';
require_once 'PHP/Depend/Util/Log.php';
require_once 'PHP/Depend/Util/Type.php';
require_once 'PHP/Depend/Parser/SymbolTable.php';
require_once 'PHP/Depend/Parser/MissingValueException.php';
require_once 'PHP/Depend/Parser/TokenStreamEndException.php';
require_once 'PHP/Depend/Parser/UnexpectedTokenException.php';

/**
 * The php source parser.
 *
 * With the default settings the parser includes annotations, better known as
 * doc comment tags, in the generated result. This means it extracts the type
 * information of @var tags for properties, and types in @return + @throws tags
 * of functions and methods. The current implementation tries to ignore all
 * scalar types from <b>boolean</b> to <b>void</b>. You should disable this
 * feature for project that have more or less invalid doc comments, because it
 * could produce invalid results.
 *
 * <code>
 *   $parser->setIgnoreAnnotations();
 * </code>
 *
 * <b>Note</b>: Due to the fact that it is possible to use the same name for
 * multiple classes and interfaces, and there is no way to determine to which
 * package it belongs, while the parser handles class, interface or method
 * signatures, the parser could/will create a code tree that doesn't reflect the
 * real source structure.
 *
 * @category  QualityAssurance
 * @package   PHP_Depend
 * @author    Manuel Pichler <mapi@pdepend.org>
 * @copyright 2008-2009 Manuel Pichler. All rights reserved.
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   Release: @package_version@
 * @link      http://pdepend.org/
 */
class PHP_Depend_Parser implements PHP_Depend_ConstantsI
{
    /**
     * Regular expression for inline type definitions in regular comments. This
     * kind of type is supported by IDEs like Netbeans or eclipse.
     */
    const REGEXP_INLINE_TYPE = '(^\s*/\*\s*
                                 @var\s+
                                   \$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s+
                                   (.*?)
                                \s*\*/\s*$)ix';

    /**
     * Regular expression for types defined in <b>throws</b> annotations of
     * method or function doc comments.
     */
    const REGEXP_THROWS_TYPE = '(\*\s*
                                 @throws\s+
                                   ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)
                                )ix';

    /**
     * Regular expression for types defined in annotations like <b>return</b> or
     * <b>var</b> in doc comments of functions and methods.
     */
    const REGEXP_RETURN_TYPE = '(\*\s*
                                 @return\s+
                                  (array\(\s*
                                    (\w+\s*=>\s*)?
                                    ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\|]*)\s*
                                  \)
                                  |
                                  ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\|]*))\s+
                                )ix';

    /**
     * Regular expression for types defined in annotations like <b>return</b> or
     * <b>var</b> in doc comments of functions and methods.
     */
    const REGEXP_VAR_TYPE = '(\*\s*
                              @var\s+
                               (array\(\s*
                                 (\w+\s*=>\s*)?
                                 ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\|]*)\s*
                               \)
                               |
                               ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\|]*))\s+
                             )ix';

    /**
     * Internal state flag, that will be set to <b>true</b> when the parser has
     * prefixed a qualified name with the actual namespace.
     *
     * @var boolean $_namespacePrefixReplaced
     */
    private $_namespacePrefixReplaced = false;

    /**
     * The name of the last detected namespace.
     *
     * @var string $_namespaceName
     */
    private $_namespaceName = null;

    /**
     * Last parsed package tag.
     *
     * @var string $_packageName
     */
    private $_packageName = self::DEFAULT_PACKAGE;

    /**
     * The package defined in the file level comment.
     *
     * @var string $_globalPackageName
     */
    private $_globalPackageName = self::DEFAULT_PACKAGE;

    /**
     * The used code tokenizer.
     *
     * @var PHP_Depend_TokenizerI $_tokenizer
     */
    private $_tokenizer = null;

    /**
     * The used data structure builder.
     *
     * @var PHP_Depend_BuilderI $_builder
     */
    private $_builder = null;

    /**
     * The currently parsed file instance.
     *
     * @var PHP_Depend_Code_File $_sourceFile
     */
    private $_sourceFile = null;

    /**
     * The symbol table used to handle PHP 5.3 use statements.
     *
     * @var PHP_Depend_Parser_SymbolTable $_useSymbolTable
     */
    private $_useSymbolTable = null;

    /**
     * The last parsed doc comment or <b>null</b>.
     *
     * @var string $_docComment
     */
    private $_docComment = null;

    /**
     * Bitfield of last parsed modifiers.
     *
     * @var integer $_modifiers
     */
    private $_modifiers = 0;

    /**
     * If this property is set to <b>true</b> the parser will ignore all doc
     * comment annotations.
     *
     * @var boolean $_ignoreAnnotations
     */
    private $_ignoreAnnotations = false;

    /**
     * Constructs a new source parser.
     *
     * @param PHP_Depend_TokenizerI $tokenizer The used code tokenizer.
     * @param PHP_Depend_BuilderI   $builder   The used node builder.
     */
    public function __construct(
        PHP_Depend_TokenizerI $tokenizer,
        PHP_Depend_BuilderI $builder
    ) {
        $this->_tokenizer = $tokenizer;
        $this->_builder   = $builder;

        $this->_useSymbolTable = new PHP_Depend_Parser_SymbolTable(true);
    }

    /**
     * Sets the ignore annotations flag. This means that the parser will ignore
     * doc comment annotations.
     *
     * @return void
     */
    public function setIgnoreAnnotations()
    {
        $this->_ignoreAnnotations = true;
    }

    /**
     * Parses the contents of the tokenizer and generates a node tree based on
     * the found tokens.
     *
     * @return void
     */
    public function parse()
    {
        // Get currently parsed source file
        $this->_sourceFile = $this->_tokenizer->getSourceFile();

        // Debug currently parsed source file.
        PHP_Depend_Util_Log::debug('Processing file ' . $this->_sourceFile);

        $this->_useSymbolTable->createScope();

        $this->reset();

        $tokenType = $this->_tokenizer->peek();
        while ($tokenType !== self::T_EOF) {

            switch ($tokenType) {

            case self::T_COMMENT:
                $this->_consumeToken(self::T_COMMENT);
                break;

            case self::T_DOC_COMMENT:
                $comment = $this->_consumeToken(self::T_DOC_COMMENT)->image;

                $this->_packageName = $this->_parsePackageAnnotation($comment);
                $this->_docComment  = $comment;
                break;

            case self::T_INTERFACE:
                $this->_parseInterfaceDeclaration();
                break;

            case self::T_CLASS:
            case self::T_FINAL:
            case self::T_ABSTRACT:
                $this->_parseClassDeclaration();
                break;

            case self::T_FUNCTION:
                $this->_parseFunctionOrClosureDeclaration();
                break;

            case self::T_USE:
                // Parse a use statement. This method has no return value but it
                // creates a new entry in the symbol map.
                $this->_parseUseDeclarations();
                break;

            case self::T_NAMESPACE:
                $this->_parseNamespaceDeclaration();
                break;

            default:
                // Consume whatever token
                $this->_consumeToken($tokenType);
                $this->reset();
                break;
            }

            $tokenType = $this->_tokenizer->peek();
        }

        $this->_useSymbolTable->destroyScope();
    }

    /**
     * Resets some object properties.
     *
     * @param integer $modifiers Optional default modifiers.
     *
     * @return void
     */
    protected function reset($modifiers = 0)
    {
        $this->_packageName = self::DEFAULT_PACKAGE;
        $this->_docComment  = null;
        $this->_modifiers   = $modifiers;
    }

    /**
     * Parses the dependencies in a interface signature.
     *
     * @return PHP_Depend_Code_Interface
     */
    private function _parseInterfaceDeclaration()
    {
        $tokens = array();

        // Consume interface keyword
        $startLine = $this->_consumeToken(self::T_INTERFACE, $tokens)->startLine;

        // Remove leading comments and get interface name
        $this->_consumeComments();
        $localName = $this->_consumeToken(self::T_STRING)->image;

        $qualifiedName = $this->_createQualifiedTypeName($localName);

        $interface = $this->_builder->buildInterface($qualifiedName);
        $interface->setSourceFile($this->_sourceFile);
        $interface->setStartLine($startLine);
        $interface->setDocComment($this->_docComment);
        $interface->setUserDefined();

        // Strip comments and fetch next token type
        $this->_consumeComments($tokens);
        $tokenType = $this->_tokenizer->peek();

        // Check for extended interfaces
        if ($tokenType === self::T_EXTENDS) {
            $this->_consumeToken(self::T_EXTENDS, $tokens);
            $this->_consumeComments($tokens);

            $tokens = array_merge($tokens, $this->_parseInterfaceList($interface));
        }
        // Handle interface body
        $this->parseTypeBody($interface);

        // Reset parser settings
        $this->reset();

        return $interface;
    }

    /**
     * Parses the dependencies in a class signature.
     *
     * @return PHP_Depend_Code_Class
     */
    private function _parseClassDeclaration()
    {
        $tokens = array();

        // Parse optional class modifiers
        $startLine = $this->_parseClassModifiers($tokens);

        // Consume class keyword and read class start line
        $token = $this->_consumeToken(self::T_CLASS, $tokens);

        // Check for previous read start line
        if ($startLine === -1) {
            $startLine = $token->startLine;
        }

        // Remove leading comments and get class name
        $this->_consumeComments();
        $localName = $this->_consumeToken(self::T_STRING, $tokens)->image;

        $qualifiedName = $this->_createQualifiedTypeName($localName);

        $class = $this->_builder->buildClass($qualifiedName);
        $class->setSourceFile($this->_sourceFile);
        $class->setStartLine($startLine);
        $class->setModifiers($this->_modifiers);
        $class->setDocComment($this->_docComment);
        $class->setUserDefined();

        $this->_consumeComments($tokens);
        $tokenType = $this->_tokenizer->peek();
        
        if ($tokenType === self::T_EXTENDS) {
            $this->_consumeToken(self::T_EXTENDS, $tokens);
            $this->_consumeComments($tokens);

            $class->setParentClassReference(
                $this->_builder->buildClassReference(
                    $this->_parseQualifiedName($tokens)
                )
            );

            $this->_consumeComments($tokens);
            $tokenType = $this->_tokenizer->peek();
        }

        if ($tokenType === self::T_IMPLEMENTS) {
            $this->_consumeToken(self::T_IMPLEMENTS, $tokens);
            $tokens = array_merge($tokens, $this->_parseInterfaceList($class));
        }
        
        // Handle class body
        $this->parseTypeBody($class);

        // Reset parser settings
        $this->reset();

        return $class;
    }

    /**
     * This method parses an optional class modifier. Valid class modifiers are
     * <b>final</b> or <b>abstract</b>. The return value of this method is the
     * start line number of a detected modifier. If no modifier was found, this
     * method will return <b>-1</b>.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference array of parsed tokens.
     *
     * @return integer
     */
    private function _parseClassModifiers(array &$tokens)
    {
        // Strip optional comments
        $this->_consumeComments($tokens);
        
        // Get next token type and check for abstract
        $tokenType = $this->_tokenizer->peek();
        if ($tokenType === self::T_ABSTRACT) {
            // Consume abstract keyword and get line number
            $line = $this->_consumeToken(self::T_ABSTRACT, $tokens)->startLine;
            // Add explicit abstract modifier
            $this->_modifiers |= self::IS_EXPLICIT_ABSTRACT;
        } else if ($tokenType === self::T_FINAL) {
            // Consume final keyword and get line number
            $line = $this->_consumeToken(self::T_FINAL, $tokens)->startLine;
            // Add final modifier
            $this->_modifiers |= self::IS_FINAL;
        } else {
            $line = -1;
        }
        
        // Strip optional comments
        $this->_consumeComments($tokens);

        return $line;
    }

    /**
     * This method parses a list of interface names as used in the <b>extends</b>
     * part of a interface declaration or in the <b>implements</b> part of a
     * class declaration.
     *
     * @param PHP_Depend_Code_AbstractClassOrInterface $abstractType The declaring
     *        type instance.
     *
     * @return array(PHP_Depend_Token)
     */
    private function _parseInterfaceList(
        PHP_Depend_Code_AbstractClassOrInterface $abstractType
    ) {
        $tokens = array();

        while (true) {
            $this->_consumeComments($tokens);

            $abstractType->addInterfaceReference(
                $this->_builder->buildInterfaceReference(
                    $this->_parseQualifiedName($tokens)
                )
            );

            $this->_consumeComments($tokens);

            $tokenType = $this->_tokenizer->peek();

            // Check for opening interface body
            if ($tokenType === self::T_CURLY_BRACE_OPEN) {
                break;
            }

            $this->_consumeToken(self::T_COMMA, $tokens);
            $this->_consumeComments($tokens);
        }

        return $tokens;
    }

    /**
     * Parses a class/interface body.
     *
     * @param PHP_Depend_Code_AbstractClassOrInterface $type The context class
     *        or interface instance.
     *
     * @return array(array)
     */
    protected function parseTypeBody(PHP_Depend_Code_AbstractClassOrInterface $type)
    {
        $tokens = array();

        // Consume comments and read opening curly brace
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_CURLY_BRACE_OPEN, $tokens);

        $defaultModifier = self::IS_PUBLIC;
        if ($type instanceof PHP_Depend_Code_Interface) {
            $defaultModifier |= self::IS_ABSTRACT;
        }
        $this->reset($defaultModifier);

        $tokenType = $this->_tokenizer->peek();

        while ($tokenType !== self::T_EOF) {

            switch ($tokenType) {

            case self::T_FUNCTION:
                $type->addMethod($this->_parseMethodDeclaration($tokens));
                $this->reset($defaultModifier);
                break;

            case self::T_VARIABLE:
                // Read variable token for $startLine property
                $token = $this->_consumeToken(self::T_VARIABLE, $tokens);

                $property = $this->_builder->buildProperty($token->image);
                $property->setDocComment($this->_docComment);
                $property->setStartLine($token->startLine);
                $property->setEndLine($token->startLine);
                $property->setSourceFile($this->_sourceFile);
                $property->setModifiers($this->_modifiers);

                $this->_prepareProperty($property);

                // TODO: Do we need an instanceof, to check that $type is a
                //       PHP_Depend_Code_Class instance or do we believe the
                //       code is correct?
                $type->addProperty($property);

                $this->reset($defaultModifier);
                break;

            case self::T_CONST:
                $type->addConstant($this->_parseTypeConstant($tokens));
                $this->reset($defaultModifier);
                break;

            case self::T_CURLY_BRACE_CLOSE:
                // Read close token, we need it for the $endLine property
                $token = $this->_consumeToken(self::T_CURLY_BRACE_CLOSE, $tokens);

                $type->setEndLine($token->endLine);
                $type->setTokens($tokens);

                $this->reset($defaultModifier);

                // Stop processing
                return $tokens;

            case self::T_ABSTRACT:
                $this->_consumeToken(self::T_ABSTRACT, $tokens);
                $this->_modifiers |= self::IS_ABSTRACT;
                break;

            case self::T_PUBLIC:
                $this->_consumeToken(self::T_PUBLIC, $tokens);
                $this->_modifiers |= self::IS_PUBLIC;
                break;

            case self::T_PRIVATE:
                $this->_consumeToken(self::T_PRIVATE, $tokens);
                $this->_modifiers |= self::IS_PRIVATE;
                $this->_modifiers = $this->_modifiers & ~self::IS_PUBLIC;
                break;

            case self::T_PROTECTED:
                $this->_consumeToken(self::T_PROTECTED, $tokens);
                $this->_modifiers |= self::IS_PROTECTED;
                $this->_modifiers = $this->_modifiers & ~self::IS_PUBLIC;
                break;

            case self::T_STATIC:
                $this->_consumeToken(self::T_STATIC, $tokens);
                $this->_modifiers |= self::IS_STATIC;
                break;

            case self::T_FINAL:
                $this->_consumeToken(self::T_FINAL, $tokens);
                $this->_modifiers |= self::IS_FINAL;
                break;

            case self::T_COMMENT:
                $this->_consumeToken(self::T_COMMENT, $tokens);
                break;

            case self::T_DOC_COMMENT:
                // Read comment token
                $token = $this->_consumeToken(self::T_DOC_COMMENT, $tokens);

                $this->_docComment = $token->image;
                break;

            default:
                // Consume anything else
                $token = $this->_consumeToken($tokenType, $tokens);
                //echo 'TOKEN: ', $token->image, PHP_EOL;

                // TODO: Handle/log unused tokens
                $this->reset($defaultModifier);
                break;
            }

            $tokenType = $this->_tokenizer->peek();
        }

        throw new PHP_Depend_Parser_TokenStreamEndException($this->_tokenizer);
    }

    /**
     * This method parses a simple function or a PHP 5.3 lambda function or
     * closure.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return PHP_Depend_Code_AbstractCallable
     * @since 0.9.5
     */
    private function _parseFunctionOrClosureDeclaration(array &$tokens = array())
    {
        // Read function keyword
        $token = $this->_consumeToken(self::T_FUNCTION, $tokens);

        // Remove leading comments
        $this->_consumeComments($tokens);

        // Check for closure or function
        if ($this->_tokenizer->peek() === self::T_PARENTHESIS_OPEN) {
            $callable = $this->_parseClosureDeclaration($tokens);
        } else {
            $callable = $this->_parseFunctionDeclaration($tokens);
        }

        $callable->setStartLine($token->startLine);
        $callable->setTokens($tokens);
        $callable->setSourceFile($this->_sourceFile);
        $callable->setDocComment($this->_docComment);
        $this->_prepareCallable($callable);

        $this->reset();

        return $callable;
    }

    /**
     * This method parses a function declaration.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference of parsed tokens.
     *
     * @return PHP_Depend_Code_Function
     * @since 0.9.5
     */
    private function _parseFunctionDeclaration(array &$tokens)
    {
        // Remove leading comments
        $this->_consumeComments($tokens);

        $returnsReference = false;
        
        // Check for returns reference token
        if ($this->_tokenizer->peek() === self::T_BITWISE_AND) {
            $this->_consumeToken(self::T_BITWISE_AND, $tokens);
            $this->_consumeComments($tokens);

            $returnsReference = true;
        }

        // Next token must be the function identifier
        $functionName = $this->_consumeToken(self::T_STRING, $tokens)->image;

        $function = $this->_builder->buildFunction($functionName);
        $this->_parseCallableDeclaration($tokens, $function);

        if ($returnsReference === true) {
            $function->setReturnsReference();
        }

        // First check for an existing namespace
        if ($this->_namespaceName !== null) {
            $packageName = $this->_namespaceName;
        } else if ($this->_packageName !== self::DEFAULT_PACKAGE) {
            $packageName = $this->_packageName;
        } else {
            $packageName = $this->_globalPackageName;
        }
        $this->_builder->buildPackage($packageName)->addFunction($function);

        return $function;
    }

    /**
     * This method parses a method declaration.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference of parsed tokens.
     *
     * @return PHP_Depend_Code_Method
     * @since 0.9.5
     */
    private function _parseMethodDeclaration(array &$tokens)
    {
        // Read function keyword for $startLine property
        $startLine = $this->_consumeToken(self::T_FUNCTION, $tokens)->startLine;

        // Remove leading comments
        $this->_consumeComments($tokens);

        $returnsReference = false;

        // Check for returns reference token
        if ($this->_tokenizer->peek() === self::T_BITWISE_AND) {
            $this->_consumeToken(self::T_BITWISE_AND, $tokens);
            $this->_consumeComments($tokens);

            $returnsReference = true;
        }

        // Next token must be the function identifier
        $methodName = $this->_consumeToken(self::T_STRING, $tokens)->image;

        $method = $this->_builder->buildMethod($methodName);
        $method->setDocComment($this->_docComment);
        $method->setStartLine($startLine);
        $method->setSourceFile($this->_sourceFile);
        $method->setModifiers($this->_modifiers);

        $this->_parseCallableDeclaration($tokens, $method);
        $this->_prepareCallable($method);

        if ($returnsReference === true) {
            $method->setReturnsReference();
        }

        return $method;
    }

    /**
     * This method parses a PHP 5.3 closure or lambda function.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return PHP_Depend_Code_Closure
     * @since 0.9.5
     */
    private function _parseClosureDeclaration(array &$tokens)
    {
        $closure = $this->_builder->buildClosure();

        $this->_parseParameterList($tokens, $closure);

        $this->_consumeComments($tokens);
        if ($this->_tokenizer->peek() === self::T_USE) {
            $this->_parseBoundVariables($tokens, $closure);
        }
        
        $this->_parseCallableBody($tokens, $closure);

        return $closure;
    }

    /**
     * Parses a function or a method and adds it to the parent context node.
     *
     * @param array(PHP_Depend_Token)          &$tokens  Reference of parsed tokens.
     * @param PHP_Depend_Code_AbstractCallable $callable The context callable.
     *
     * @return void
     */
    private function _parseCallableDeclaration(
        array &$tokens,
        PHP_Depend_Code_AbstractCallable $callable
    ) {
        $this->_parseParameterList($tokens, $callable);
        $this->_consumeComments($tokens);
        
        if ($this->_tokenizer->peek() === self::T_CURLY_BRACE_OPEN) {
            // Get function body dependencies
            $this->_parseCallableBody($tokens, $callable);
        } else {
            $token = $this->_consumeToken(self::T_SEMICOLON, $tokens);
            $callable->setEndLine($token->endLine);
        }
    }

    /**
     * Extracts all dependencies from a callable signature.
     *
     * @param array(PHP_Depend_Token)          &$tokens  Reference for parsed tokens.
     * @param PHP_Depend_Code_AbstractCallable $function The context callable.
     *
     * @return void
     * @since 0.9.5
     */
    private function _parseParameterList(
        array &$tokens,
        PHP_Depend_Code_AbstractCallable $function
    ) {
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_PARENTHESIS_OPEN, $tokens);
        $this->_consumeComments($tokens);

        $tokenType = $this->_tokenizer->peek();

        // Check for function without parameters
        if ($tokenType === self::T_PARENTHESIS_CLOSE) {
            $this->_consumeToken(self::T_PARENTHESIS_CLOSE, $tokens);
            return;
        }

        $position   = 0;
        $parameters = array();

        while ($tokenType !== self::T_EOF) {
            $parameter = $this->_parseParameter($tokens);
            $parameter->setPosition(count($parameters));

            // Add new parameter to function
            $function->addParameter($parameter);

            // Store parameter for later isOptional calculation.
            $parameters[] = $parameter;

            $this->_consumeComments($tokens);

            $tokenType = $this->_tokenizer->peek();

            // Check for following parameter
            if ($tokenType !== self::T_COMMA) {
                break;
            }

            // It must be a comma
            $this->_consumeToken(self::T_COMMA, $tokens);
        }

        $optional = true;
        foreach (array_reverse($parameters) as $parameter) {
            if ($parameter->isDefaultValueAvailable() === false) {
                $optional = false;
            }
            $parameter->setOptional($optional);
        }

        $this->_consumeToken(self::T_PARENTHESIS_CLOSE, $tokens);
    }

    /**
     * This method parses a single function or method parameter and returns the
     * corresponding ast instance. Additionally this method fills the tokens
     * array with all found tokens.
     * 
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return PHP_Depend_Code_Parameter
     */
    private function _parseParameter(array &$tokens)
    {
        $parameterRef   = false;
        $parameterType  = null;
        $parameterArray = false;

        $this->_consumeComments($tokens);
        $tokenType = $this->_tokenizer->peek();

        // Check for class/interface type hint
        if ($tokenType === self::T_STRING || $tokenType === self::T_BACKSLASH) {
            // Get type identifier
            $parameterType = $this->_parseQualifiedName($tokens);

            // Remove ending comments
            $this->_consumeComments($tokens);

            // Get next token type
            $tokenType = $this->_tokenizer->peek();
        } else if ($tokenType === self::T_ARRAY) {
            // Mark as array parameter
            $parameterArray = true;

            // Consume array token and remove comments
            $this->_consumeToken(self::T_ARRAY, $tokens);
            $this->_consumeComments($tokens);

            // Get next token type
            $tokenType = $this->_tokenizer->peek();
        }

        // Check for parameter by reference
        if ($tokenType === self::T_BITWISE_AND) {
            // Set by ref flag
            $parameterRef = true;

            // Consume bitwise and token
            $this->_consumeToken(self::T_BITWISE_AND, $tokens);
            $this->_consumeComments($tokens);

            // Get next token type
            $tokenType = $this->_tokenizer->peek();
        }

        // Next token must be the parameter variable
        $token = $this->_consumeToken(self::T_VARIABLE, $tokens);
        $this->_consumeComments($tokens);

        $parameter = $this->_builder->buildParameter($token->image);
        $parameter->setPassedByReference($parameterRef);
        $parameter->setArray($parameterArray);

        if ($parameterType !== null) {
            $parameter->setClassReference(
                $this->_builder->buildClassOrInterfaceReference($parameterType)
            );
        }

        // Check for a default value
        if ($this->_tokenizer->peek() !== self::T_EQUAL) {
            return $parameter;
        }

        $this->_consumeToken(self::T_EQUAL, $tokens);
        $this->_consumeComments($tokens);

        $parameter->setValue($this->_parseDefaultValue($tokens));

        return $parameter;
    }

    /**
     * Extracts all dependencies from a callable body.
     *
     * @param array(array)                     &$outTokens Collected tokens.
     * @param PHP_Depend_Code_AbstractCallable $callable   The context callable.
     *
     * @return void
     */
    private function _parseCallableBody(
        array &$outTokens,
        PHP_Depend_Code_AbstractCallable $callable
    ) {
        $this->_useSymbolTable->createScope();
        
        $curly  = 0;
        $tokens = array();

        $tokenType = $this->_tokenizer->peek();

        while ($tokenType !== self::T_EOF) {

            switch ($tokenType) {
        
            case self::T_CATCH:
                // Consume catch keyword and the opening parenthesis
                $this->_consumeToken(self::T_CATCH, $tokens);
                $this->_consumeComments($tokens);
                $this->_consumeToken(self::T_PARENTHESIS_OPEN, $tokens);

                $callable->addDependencyClassReference(
                    $this->_builder->buildClassOrInterfaceReference(
                        $this->_parseQualifiedName($tokens)
                    )
                );
                break;

            case self::T_NEW:
                // Consume the
                $this->_consumeToken(self::T_NEW, $tokens);
                $this->_consumeComments($tokens);

                // Peek next token and look for a static type identifier
                $peekType = $this->_tokenizer->peek();

                // If this is a dynamic instantiation, do not add dependency.
                // Something like: $bar instanceof $className
                if ($peekType === self::T_STRING
                    || $peekType === self::T_BACKSLASH
                    || $peekType === self::T_NAMESPACE
                ) {
                    $callable->addDependencyClassReference(
                        $this->_builder->buildClassReference(
                            $this->_parseQualifiedName($tokens)
                        )
                    );
                }
                break;

            case self::T_INSTANCEOF:
                $this->_consumeToken(self::T_INSTANCEOF, $tokens);
                $this->_consumeComments($tokens);

                // Peek next token and look for a static type identifier
                $peekType = $this->_tokenizer->peek();

                // If this is a dynamic instantiation, do not add dependency.
                // Something like: $bar instanceof $className
                if ($peekType === self::T_STRING
                    || $peekType === self::T_BACKSLASH
                    || $peekType === self::T_NAMESPACE
                ) {
                    $callable->addDependencyClassReference(
                        $this->_builder->buildClassOrInterfaceReference(
                            $this->_parseQualifiedName($tokens)
                        )
                    );
                }
                break;

            case self::T_STRING:
            case self::T_BACKSLASH:
            case self::T_NAMESPACE:
                $qualifiedName = $this->_parseQualifiedName($tokens);

                // Remove comments
                $this->_consumeComments($tokens);

                // Test for static method, property or constant access
                if ($this->_tokenizer->peek() !== self::T_DOUBLE_COLON) {
                    break;
                }

                // Consume double colon and optional comments
                $this->_consumeToken(self::T_DOUBLE_COLON, $tokens);
                $this->_consumeComments($tokens);

                // Get next token type
                $tokenType = $this->_tokenizer->peek();

                // T_STRING == method or constant, T_VARIABLE == property
                if ($tokenType === self::T_STRING
                    || $tokenType === self::T_VARIABLE
                ) {
                    $this->_consumeToken($tokenType, $tokens);

                    $callable->addDependencyClassReference(
                        $this->_builder->buildClassOrInterfaceReference(
                            $qualifiedName
                        )
                    );
                }
                break;

            case self::T_CURLY_BRACE_OPEN:
                $this->_consumeToken(self::T_CURLY_BRACE_OPEN, $tokens);
                ++$curly;
                break;

            case self::T_CURLY_BRACE_CLOSE:
                $this->_consumeToken(self::T_CURLY_BRACE_CLOSE, $tokens);
                --$curly;
                break;

            case self::T_DOUBLE_QUOTE:
                $this->_consumeToken(self::T_DOUBLE_QUOTE, $tokens);
                $this->_skipEncapsultedBlock($tokens, self::T_DOUBLE_QUOTE);
                break;

            case self::T_BACKTICK:
                $this->_consumeToken(self::T_BACKTICK, $tokens);
                $this->_skipEncapsultedBlock($tokens, self::T_BACKTICK);
                break;

            case self::T_FUNCTION:
                $this->_parseFunctionOrClosureDeclaration();
                break;

            case self::T_COMMENT:
                $token = $this->_consumeToken(self::T_COMMENT, $tokens);

                // Check for inline type definitions like: /* @var $o FooBar */
                if (preg_match(self::REGEXP_INLINE_TYPE, $token->image, $match)) {
                    // TODO Refs #66: This should be done in a post process
                    // Create a referenced class or interface instance

                    $callable->addDependencyClassReference(
                        $this->_builder->buildClassOrInterfaceReference($match[1])
                    );
                }
                break;

            default:
                $this->_consumeToken($tokenType, $tokens);
                break;
            }

            if ($curly === 0) {
                // Get the last token
                $token = end($tokens);
                // Set end line number
                $callable->setEndLine($token->startLine);
                // Set all tokens for this function
                $callable->setTokens($tokens);

                // Append all tokens to parent's reference array
                foreach ($tokens as $token) {
                    $outTokens[] = $token;
                }

                $this->_useSymbolTable->destroyScope();

                // Stop processing
                return;
            }

            $tokenType = $this->_tokenizer->peek();
        }

        throw new PHP_Depend_Parser_TokenStreamEndException($this->_tokenizer);
    }

    /**
     * Parses a list of bound closure variables.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     * @param PHP_Depend_Code_Closure $closure The parent closure instance.
     *
     * @return void
     * @since 0.9.5
     */
    private function _parseBoundVariables(
        array &$tokens,
        PHP_Depend_Code_Closure $closure
    ) {
        // Consume use keyword
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_USE, $tokens);

        // Consume opening parenthesis
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_PARENTHESIS_OPEN, $tokens);

        while ($this->_tokenizer->peek() !== self::T_EOF) {
            // Consume leading comments
            $this->_consumeComments($tokens);

            // Check for by-ref operator
            if ($this->_tokenizer->peek() === self::T_BITWISE_AND) {
                $this->_consumeToken(self::T_BITWISE_AND, $tokens);
                $this->_consumeComments($tokens);
            }

            // Read bound variable
            $this->_consumeToken(self::T_VARIABLE, $tokens);
            $this->_consumeComments($tokens);

            // Check for further bound variables
            if ($this->_tokenizer->peek() === self::T_COMMA) {
                $this->_consumeToken(self::T_COMMA, $tokens);
                continue;
            }
            break;
        }

        // Consume closing parenthesis
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_PARENTHESIS_CLOSE, $tokens);
    }

    /**
     * Parses a php class/method name chain.
     *
     * <code>
     * PHP\Depend\Parser::parse();
     * </code>
     *
     * @param array(PHP_Depend_Token) &$tokens Reference array of all parsed tokens.
     *
     * @return string
     */
    private function _parseQualifiedName(array &$tokens)
    {
        $fragments = $this->_parseQualifiedNameRaw($tokens);
        
        // Check for fully qualified name
        if ($fragments[0] === '\\') {
            return join('', $fragments);
        }

        // Search for an use alias
        $mapsTo = $this->_useSymbolTable->lookup($fragments[0]);
        if ($mapsTo !== null) {
            // Remove alias and add real namespace
            array_shift($fragments);
            array_unshift($fragments, $mapsTo);
        } else if ($this->_namespaceName !== null 
            && $this->_namespacePrefixReplaced === false
        ) {
            // Prepend current namespace
            array_unshift($fragments, $this->_namespaceName, '\\');
        }
        return join('', $fragments);
    }

    /**
     * This method parses a qualified PHP 5.3 class, interface and namespace
     * identifier and returns the collected tokens as a string array.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for parsed tokens.
     *
     * @return array(string)
     * @since 0.9.5
     */
    private function _parseQualifiedNameRaw(array &$tokens)
    {
        // Reset namespace prefix flag
        $this->_namespacePrefixReplaced = false;

        // Consume comments and fetch first token type
        $this->_consumeComments($tokens);
        $tokenType = $this->_tokenizer->peek();

        $qualifiedName = array();

        // Check for local name
        if ($tokenType === self::T_STRING) {
            $qualifiedName[] = $this->_consumeToken(self::T_STRING, $tokens)->image;

            $this->_consumeComments($tokens);
            $tokenType = $this->_tokenizer->peek();

            // Stop here for simple identifier
            if ($tokenType !== self::T_BACKSLASH) {
                return $qualifiedName;
            }
        } else if ($tokenType === self::T_NAMESPACE) {
            // Consume namespace keyword
            $this->_consumeToken(self::T_NAMESPACE, $tokens);
            $this->_consumeComments($tokens);

            // Add current namespace as first token
            $qualifiedName = array((string) $this->_namespaceName);

            // Set prefixed flag to true
            $this->_namespacePrefixReplaced = true;
        }

        do {
            // Next token must be a namespace separator
            $this->_consumeToken(self::T_BACKSLASH, $tokens);
            $this->_consumeComments($tokens);

            // Next token must be a namespace identifier
            $token = $this->_consumeToken(self::T_STRING, $tokens);
            $this->_consumeComments($tokens);

            // Append to qualified name
            $qualifiedName[] = '\\';
            $qualifiedName[] = $token->image;

            // Get next token type
            $tokenType = $this->_tokenizer->peek();
        } while ($tokenType === self::T_BACKSLASH);

        return $qualifiedName;
    }

    /**
     * This method parses a PHP 5.3 namespace declaration.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for parsed tokens.
     *
     * @return void
     * @since 0.9.5
     */
    private function _parseNamespaceDeclaration(array &$tokens = array())
    {
        // Consume namespace keyword and strip optional comments
        $this->_consumeToken(self::T_NAMESPACE, $tokens);
        $this->_consumeComments($tokens);

        // Lookup next token type
        $tokenType = $this->_tokenizer->peek();

        // Search for a namespace identifier
        if ($tokenType === self::T_STRING) {
            // Reset namespace property
            $this->_namespaceName = null;

            // Read qualified namespace identifier
            $qualifiedName = $this->_parseQualifiedName($tokens);

            // Consume optional comments an check for namespace scope
            $this->_consumeComments($tokens);

            if ($this->_tokenizer->peek() === self::T_CURLY_BRACE_OPEN) {
                // Consume opening curly brace
                $this->_consumeToken(self::T_CURLY_BRACE_OPEN, $tokens);
            } else {
                // Consume closing semicolon token
                $this->_consumeToken(self::T_SEMICOLON, $tokens);
            }

            // Create a package for this namespace
            $this->_namespaceName = $qualifiedName;
            $this->_builder->buildPackage($qualifiedName);
        } else if ($tokenType === self::T_BACKSLASH) {
            // Same namespace reference, something like:
            //   new namespace\Foo();
            // or:
            //   $x = namespace\foo::bar();

            // Now parse a qualified name
            $this->_parseQualifiedNameRaw($tokens);
        } else {
            // Consume opening curly brace
            $this->_consumeToken(self::T_CURLY_BRACE_OPEN, $tokens);

            // Create a package for this namespace
            $this->_namespaceName = '';
            $this->_builder->buildPackage('');
        }

        $this->reset();
    }

    /**
     * This method parses a list of PHP 5.3 use declarations and adds a mapping
     * between short name and full qualified name to the use symbol table.
     *
     * <code>
     * use \foo\bar as fb,
     *     \foobar\Bar;
     * </code>
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return void
     * @since 0.9.5
     */
    private function _parseUseDeclarations(array &$tokens = array())
    {
        // Consume use keyword
        $this->_consumeToken(self::T_USE, $tokens);
        $this->_consumeComments($tokens);

        // Parse all use declarations
        $this->_parseUseDeclaration($tokens);
        $this->_consumeComments($tokens);

        // Consume closing semicolon
        $this->_consumeToken(self::T_SEMICOLON, $tokens);

        // Reset any previous state
        $this->reset();
    }

    /**
     * This method parses a single use declaration and adds a mapping between
     * short name and full qualified name to the use symbol table. 
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return void
     * @since 0.9.5
     */
    private function _parseUseDeclaration(array &$tokens)
    {
        $fragments = $this->_parseQualifiedNameRaw($tokens);
        $this->_consumeComments($tokens);

        if ($this->_tokenizer->peek() === self::T_AS) {
            $this->_consumeToken(self::T_AS, $tokens);
            $this->_consumeComments($tokens);

            $image = $this->_consumeToken(self::T_STRING, $tokens)->image;
            $this->_consumeComments($tokens);
        } else {
            $image = end($fragments);
        }

        // Add mapping between image and qualified name to symbol table
        $this->_useSymbolTable->add($image, join('', $fragments));

        // Check for a following use declaration
        if ($this->_tokenizer->peek() === self::T_COMMA) {
            // Consume comma token and comments
            $this->_consumeToken(self::T_COMMA, $tokens);
            $this->_consumeComments($tokens);

            $this->_parseUseDeclaration($tokens);
        }
    }

    /**
     * This method parses a class or interface constant.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return PHP_Depend_Code_Constant
     * @since 0.9.5
     */
    private function _parseTypeConstant(array &$tokens)
    {
        // Consume const keyword
        $this->_consumeToken(self::T_CONST, $tokens);

        // Remove leading comments and read constant name
        $this->_consumeComments($tokens);
        $token = $this->_consumeToken(self::T_STRING, $tokens);

        $constant = $this->_builder->buildTypeConstant($token->image);
        $constant->setDocComment($this->_docComment);
        $constant->setStartLine($token->startLine);
        $constant->setEndLine($token->startLine);
        $constant->setSourceFile($this->_sourceFile);

        return $constant;
    }

    /**
     * This method will parse the default value of a parameter or property
     * declaration.
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return PHP_Depend_Code_Value
     * @since 0.9.5
     */
    private function _parseDefaultValue(array &$tokens)
    {
        $defaultValue = new PHP_Depend_Code_Value();

        $this->_consumeComments($tokens);

        // By default all parameters positive signed
        $signed = 1;

        $tokenType = $this->_tokenizer->peek();
        while ($tokenType !== self::T_EOF) {

            switch ($tokenType) {

            case self::T_COMMA:            
            case self::T_SEMICOLON:
            case self::T_PARENTHESIS_CLOSE:
                if ($defaultValue->isValueAvailable() === true) {
                    return $defaultValue;
                }
                throw new PHP_Depend_Parser_MissingValueException($this->_tokenizer);

            case self::T_NULL:
                $token = $this->_consumeToken(self::T_NULL, $tokens);
                $defaultValue->setValue(null);
                break;

            case self::T_TRUE:
                $token = $this->_consumeToken(self::T_TRUE, $tokens);
                $defaultValue->setValue(true);
                break;

            case self::T_FALSE:
                $token = $this->_consumeToken(self::T_FALSE, $tokens);
                $defaultValue->setValue(false);
                break;

            case self::T_LNUMBER:
                $token = $this->_consumeToken(self::T_LNUMBER, $tokens);
                $defaultValue->setValue($signed * (int) $token->image);
                break;

            case self::T_DNUMBER:
                $token = $this->_consumeToken(self::T_DNUMBER, $tokens);
                $defaultValue->setValue($signed * (double) $token->image);
                break;

            case self::T_CONSTANT_ENCAPSED_STRING:
                $token = $this->_consumeToken(
                    self::T_CONSTANT_ENCAPSED_STRING,
                    $tokens
                );
                $defaultValue->setValue(substr($token->image, 1, -1));
                break;

            case self::T_ARRAY:
                $defaultValue->setValue($this->_parseDefaultValueArray($tokens));
                break;

            case self::T_DOUBLE_COLON:
                $this->_consumeToken(self::T_DOUBLE_COLON, $tokens);
                break;

            case self::T_PLUS:
                $this->_consumeToken(self::T_PLUS, $tokens);
                break;

            case self::T_MINUS:
                $this->_consumeToken(self::T_MINUS, $tokens);
                $signed *= -1;
                break;

            case self::T_DIR:
            case self::T_FILE:
            case self::T_LINE:
            case self::T_SELF:
            case self::T_NS_C:
            case self::T_FUNC_C:
            case self::T_STRING:
            case self::T_STATIC:
            case self::T_CLASS_C:
            case self::T_METHOD_C:
            case self::T_BACKSLASH:
            
                // There is a default value but we don't handle it at the moment.
                $defaultValue->setValue(null);
                $this->_consumeToken($tokenType, $tokens);
                break;

            default:
                throw new PHP_Depend_Parser_UnexpectedTokenException(
                    $this->_tokenizer
                );
            }
            
            $this->_consumeComments($tokens);

            $tokenType = $this->_tokenizer->peek();
        }

        // We should never reach this, so throw an exception
        throw new PHP_Depend_Parser_TokenStreamEndException($this->_tokenizer);
    }

    /**
     * This method parses an array as it is used for for parameter or property
     * default values.
     *
     * Note: At the moment the implementation of this method only returns an
     *       empty array, but consumes all tokens that belong to the array
     *       declaration.
     *
     * TODO: Implement array content/value handling, but how should we handle
     *       constant values like array(self::FOO, FOOBAR)?
     *
     * @param array(PHP_Depend_Token) &$tokens Reference for all parsed tokens.
     *
     * @return array
     * @since 0.9.5
     */
    private function _parseDefaultValueArray(array &$tokens)
    {
        $defaultValue = array();

        // Fetch all tokens that belong to this array
        $this->_consumeToken(self::T_ARRAY, $tokens);
        $this->_consumeComments($tokens);
        $this->_consumeToken(self::T_PARENTHESIS_OPEN, $tokens);

        $parenthesis = 1;

        $tokenType = $this->_tokenizer->peek();
        while ($tokenType !== self::T_EOF) {

            switch ($tokenType) {

            case self::T_PARENTHESIS_CLOSE:
                if (--$parenthesis === 0) {
                    break 2;
                }
                $this->_consumeToken(self::T_PARENTHESIS_CLOSE, $tokens);
                break;

            case self::T_PARENTHESIS_OPEN:
                $this->_consumeToken(self::T_PARENTHESIS_OPEN, $tokens);
                ++$parenthesis;
                break;

            case self::T_COMMENT:
            case self::T_DOC_COMMENT:
            case self::T_DOUBLE_COLON:
            case self::T_STRING:
            case self::T_BACKSLASH:
            case self::T_ARRAY:
            case self::T_NULL:
            case self::T_TRUE:
            case self::T_FALSE:
            case self::T_EQUAL:
            case self::T_CONSTANT_ENCAPSED_STRING:
            case self::T_DNUMBER:
            case self::T_LNUMBER:
            case self::T_DOUBLE_ARROW:
            case self::T_FILE:
            case self::T_FUNC_C:
            case self::T_LINE:
            case self::T_METHOD_C:
            case self::T_NS_C:
            case self::T_NUM_STRING:
            case self::T_STATIC:
            case self::T_COMMA:
            case self::T_PLUS:
            case self::T_MINUS:
            case self::T_SELF:
            case self::T_DIR:
                $this->_consumeToken($tokenType, $tokens);
                break;

            default:
                break 2;
            }

            $tokenType = $this->_tokenizer->peek();
        }

        // Read closing parenthesis
        $this->_consumeToken(self::T_PARENTHESIS_CLOSE, $tokens);
        
        return $defaultValue;
    }

    /**
     * This method creates a qualified class or interface name based on the
     * current parser state. By default method uses the current namespace scope
     * as prefix for the given local name. And it will fallback to a previously
     * parsed package annotation, when no namespace declaration was parsed.
     *
     * @param string $localName The local class or interface name.
     *
     * @return string
     */
    private function _createQualifiedTypeName($localName)
    {
        $separator = '\\';
        $namespace = $this->_namespaceName;

        if ($namespace === null) {
            $separator = self::PACKAGE_SEPARATOR;
            $namespace = $this->_packageName;
        }

        return $namespace . $separator . $localName;
    }

    /**
     * Extracts the @package information from the given comment.
     *
     * @param string $comment A doc comment block.
     *
     * @return string
     */
    private function _parsePackageAnnotation($comment)
    {
        $package = self::DEFAULT_PACKAGE;
        if (preg_match('#\*\s*@package\s+(.*)#', $comment, $match)) {
            $package = trim($match[1]);
            if (preg_match('#\*\s*@subpackage\s+(.*)#', $comment, $match)) {
                $package .= self::PACKAGE_SEPARATOR . trim($match[1]);
            }
        }

        // Check for doc level comment
        if ($this->_globalPackageName === self::DEFAULT_PACKAGE
            && $this->isFileComment() === true
        ) {
            $this->_globalPackageName = $package;

            $this->_sourceFile->setDocComment($comment);
        }
        return $package;
    }

    /**
     * Checks that the current token could be used as file comment.
     *
     * This method checks that the previous token is an open tag and the following
     * token is not a class, a interface, final, abstract or a function.
     *
     * @return boolean
     */
    protected function isFileComment()
    {
        if ($this->_tokenizer->prev() !== self::T_OPEN_TAG) {
            return false;
        }

        $notExpectedTags = array(
            self::T_CLASS,
            self::T_FINAL,
            self::T_ABSTRACT,
            self::T_FUNCTION,
            self::T_INTERFACE
        );

        return !in_array($this->_tokenizer->peek(), $notExpectedTags, true);
    }

    /**
     * Skips an encapsulted block like strings or backtick strings.
     *
     * @param array(array) &$tokens  The tokens array.
     * @param integer      $endToken The end token.
     *
     * @return void
     */
    private function _skipEncapsultedBlock(&$tokens, $endToken)
    {
        while ($this->_tokenizer->peek() !== $endToken) {
            $tokens[] = $this->_tokenizer->next();
        }
        $tokens[] = $this->_tokenizer->next();
    }

    /**
     * Returns the class names of all <b>throws</b> annotations with in the
     * given comment block.
     *
     * @param string $comment The context doc comment block.
     *
     * @return array
     */
    private function _parseThrowsAnnotations($comment)
    {
        $throws = array();
        if (preg_match_all(self::REGEXP_THROWS_TYPE, $comment, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $throws[] = $match;
            }
        }
        return $throws;
    }

    /**
     * This method parses the given doc comment text for a return annotation and
     * it returns the found return type.
     *
     * @param string $comment A doc comment text.
     *
     * @return string
     */
    private function _parseReturnAnnotation($comment)
    {
        if (preg_match(self::REGEXP_RETURN_TYPE, $comment, $match) > 0) {
            foreach (explode('|', end($match)) as $type) {
                if (PHP_Depend_Util_Type::isScalarType($type) === false) {
                    return $type;
                }
            }
        }
        return null;
    }

    /**
     * This method parses the given doc comment text for a var annotation and
     * it returns the found property type.
     *
     * @param string $comment A doc comment text.
     *
     * @return string
     */
    private function _parseVarAnnotation($comment)
    {
        if (preg_match(self::REGEXP_VAR_TYPE, $comment, $match) > 0) {
            foreach (explode('|', end($match)) as $type) {
                if (PHP_Depend_Util_Type::isScalarType($type) === false) {
                    return $type;
                }
            }
        }
        return null;
    }

    /**
     * Extracts non scalar types from the property doc comment and sets the
     * matching type instance.
     *
     * @param PHP_Depend_Code_Property $property The context property instance.
     *
     * @return void
     */
    private function _prepareProperty(PHP_Depend_Code_Property $property)
    {
        // Skip, if ignore annotations is set
        if ($this->_ignoreAnnotations === true) {
            return;
        }

        // Get type annotation
        $qualifiedName = $this->_parseVarAnnotation($property->getDocComment());
        if ($qualifiedName !== null) {
            $property->setClassReference(
                $this->_builder->buildClassOrInterfaceReference($qualifiedName)
            );
        }
    }

    /**
     * Extracts documented <b>throws</b> and <b>return</b> types and sets them
     * to the given <b>$callable</b> instance.
     *
     * @param PHP_Depend_Code_AbstractCallable $callable The context callable.
     *
     * @return void
     */
    private function _prepareCallable(PHP_Depend_Code_AbstractCallable $callable)
    {
        // Skip, if ignore annotations is set
        if ($this->_ignoreAnnotations === true) {
            return;
        }

        // Get all @throws Types
        $throws = $this->_parseThrowsAnnotations($callable->getDocComment());
        foreach ($throws as $qualifiedName) {
            $callable->addExceptionClassReference(
                $this->_builder->buildClassOrInterfaceReference($qualifiedName)
            );
        }

        // Get return annotation
        $qualifiedName = $this->_parseReturnAnnotation($callable->getDocComment());
        if ($qualifiedName !== null) {
            $callable->setReturnClassReference(
                $this->_builder->buildClassOrInterfaceReference($qualifiedName)
            );
        }
    }

    /**
     * This method will consume the next token in the token stream. It will
     * throw an exception if the type of this token is not identical with
     * <b>$tokenType</b>.
     *
     * @param integer $tokenType The next expected token type.
     * @param array   &$tokens   Optional token storage array.
     *
     * @return PHP_Depend_Token
     */
    private function _consumeToken($tokenType, &$tokens = array())
    {
        if ($this->_tokenizer->peek() === self::T_EOF) {
            throw new PHP_Depend_Parser_TokenStreamEndException($this->_tokenizer);
        }

        if ($this->_tokenizer->peek() !== $tokenType) {
            throw new PHP_Depend_Parser_UnexpectedTokenException($this->_tokenizer);
        }

        return $tokens[] = $this->_tokenizer->next();
    }

    /**
     * This method will consume all comment tokens from the token stream.
     *
     * @param array &$tokens Optional token storage array.
     *
     * @return integer
     */
    private function _consumeComments(&$tokens = array())
    {
        $comments = array(self::T_COMMENT, self::T_DOC_COMMENT);

        while (($type = $this->_tokenizer->peek()) !== self::T_EOF) {
            if (in_array($type, $comments, true) === false) {
                break;
            }
            $tokens[] = $this->_tokenizer->next();
        }
        return count($tokens);
    }
}
?>
