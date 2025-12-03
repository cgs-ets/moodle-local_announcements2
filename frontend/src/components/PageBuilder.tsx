import { useState, useEffect } from 'react';
import { Box, Button, ActionIcon, Menu, Textarea, Paper, Group, Text } from '@mantine/core';
import { IconGripVertical, IconPlus, IconTrash, IconCode, IconTable, IconFile, IconEdit, IconChevronLeft, IconChevronRight, IconGripHorizontal, IconCopy, IconDotsVertical } from '@tabler/icons-react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { RichTextEditor } from '@mantine/tiptap';
import { useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { FileUploader } from './FileUploader';
import { Row, Block, BlockType } from '../types/types';

type PageBuilderProps = {
  rows: Row[];
  onChange: (rows: Row[]) => void;
}

// Generate unique ID
const generateId = () => `id-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

// Block component
type BlockComponentProps = {
  block: Block;
  rowId: string;
  blockIndex: number;
  onUpdate: (blockId: string, content: string, attachments?: string) => void;
  onDelete: (blockId: string) => void;
  onInsertLeft: (blockId: string) => void;
  onInsertRight: (blockId: string) => void;
  onTypeChange: (blockId: string, type: BlockType) => void;
  dragHandleProps?: any;
  onCopy?: (block: Block) => void;
  hasCopiedBlock?: boolean;
}

function BlockComponent({ 
  block, 
  onUpdate, 
  onDelete, 
  onInsertLeft, 
  onInsertRight,
  onTypeChange,
  dragHandleProps,
  onCopy,
  hasCopiedBlock = false
}: BlockComponentProps) {
  const editor = useEditor({
    extensions: [StarterKit],
    content: block.type === 'text' ? block.content : '',
    onUpdate: ({ editor }) => {
      const html = editor.getHTML();
      if (html !== block.content) {
        onUpdate(block.id, html);
      }
    },
  });

  // Update editor content when block content changes externally (but not from editor itself)
  useEffect(() => {
    if (editor && block.type === 'text') {
      const currentContent = editor.getHTML();
      if (currentContent !== block.content) {
        editor.commands.setContent(block.content, { parseOptions: { preserveWhitespace: false } });
      }
    }
  }, [block.content, block.type, editor]);

  const renderBlockContent = () => {
    switch (block.type) {
      case 'text':
        if (!editor) {
          return <Box p="md">Loading editor...</Box>;
        }
        return (
          <RichTextEditor editor={editor} className="!rounded-none !border-none">
            <RichTextEditor.Toolbar sticky stickyOffset={60} className="!border-none !rounded-none !pb-0 !pr-16 !m-0">
              <RichTextEditor.ControlsGroup>
                <RichTextEditor.Bold />
                <RichTextEditor.Italic />
                <RichTextEditor.Underline />
                <RichTextEditor.Strikethrough />
                <RichTextEditor.ClearFormatting />
              </RichTextEditor.ControlsGroup>
              <RichTextEditor.ControlsGroup>
                <RichTextEditor.Blockquote />
                <RichTextEditor.Hr />
                <RichTextEditor.BulletList />
                <RichTextEditor.OrderedList />
              </RichTextEditor.ControlsGroup>
              <RichTextEditor.ControlsGroup>
                <RichTextEditor.Link />
                <RichTextEditor.Unlink />
              </RichTextEditor.ControlsGroup>
            </RichTextEditor.Toolbar>
            <RichTextEditor.Content style={{ cursor: 'text' }} />
          </RichTextEditor>
        );
      case 'code':
        return (
          <Textarea
            value={block.content}
            onChange={(e) => onUpdate(block.id, e.target.value)}
            placeholder="Enter code here..."
            autosize
            minRows={4}
            styles={{
              input: {
                fontFamily: 'monospace',
                border: 'none',
              },
            }}
          />
        );
      case 'table':
        return (
          <Box p="md" style={{ border: '1px dashed #ccc', borderRadius: 4 }}>
            <Textarea
              value={block.content}
              onChange={(e) => onUpdate(block.id, e.target.value)}
              placeholder="Table builder - Enter table HTML or markdown here..."
              minRows={5}
            />
          </Box>
        );
      case 'file':
        return (
          <FileUploader
            desc="Drag and drop image/file here..."
            maxFiles={1}
            maxSize={10}
            existingfiles={[]}
            setState={(value) => onUpdate(block.id, '', value)}
            showPreview={true}
          />
        );
      default:
        return null;
    }
  };

  return (
    <div className="relative" >
      <Group 
        justify="space-between" 
        gap="0" 
        mb="xs" 
        style={{ position: 'absolute', top: 8, right: 8, zIndex: 10 }}
        className="bg-white/50 backdrop-blur-sm rounded-md"
      >
        <ActionIcon variant="subtle" {...dragHandleProps}>
          <IconGripHorizontal size={20} style={{ cursor: 'grab', color: '#999' }} />
        </ActionIcon>
        <Menu shadow="md" width={200}>
          <Menu.Target>
            <ActionIcon variant="subtle" >
              <IconDotsVertical size={16} />
            </ActionIcon>
          </Menu.Target>
          <Menu.Dropdown>
            <Menu.Label>Block Type</Menu.Label>
            <Menu.Item 
              leftSection={<IconEdit size={14} />}
              onClick={() => onTypeChange(block.id, 'text')}
              disabled={block.type === 'text'}
            >
              Text Editor
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconCode size={14} />}
              onClick={() => onTypeChange(block.id, 'code')}
              disabled={block.type === 'code'}
            >
              Code Block
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconTable size={14} />}
              onClick={() => onTypeChange(block.id, 'table')}
              disabled={block.type === 'table'}
            >
              Table Builder
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconFile size={14} />}
              onClick={() => onTypeChange(block.id, 'file')}
              disabled={block.type === 'file'}
            >
              File Uploader
            </Menu.Item>
            <Menu.Divider />
            <Menu.Label>Actions</Menu.Label>
            {onCopy && (
              <Menu.Item 
                leftSection={<IconCopy size={14} />}
                onClick={() => onCopy(block)}
              >
                Copy Block
              </Menu.Item>
            )}
            <Menu.Item 
              leftSection={<IconChevronLeft size={14} />}
              onClick={() => onInsertLeft(block.id)}
            >
              {hasCopiedBlock ? 'Paste Block Left' : 'Insert Block Left'}
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconChevronRight size={14} />}
              onClick={() => onInsertRight(block.id)}
            >
              {hasCopiedBlock ? 'Paste Block Right' : 'Insert Block Right'}
            </Menu.Item>
            <Menu.Divider />
            <Menu.Item 
              color="red"
              leftSection={<IconTrash size={14} />}
              onClick={() => onDelete(block.id)}
            >
              Delete Block
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
      </Group>
      <div>
        {renderBlockContent()}
      </div>
    </div>
  );
}

// Row component
type RowComponentProps = {
  row: Row;
  rowIndex: number;
  onUpdate: (rowId: string, blocks: Block[]) => void;
  onDelete: (rowId: string) => void;
  onAddRow: () => void;
  dragHandleProps?: any;
  copiedBlock: Block | null;
  onCopy: (block: Block) => void;
  onClearCopy: () => void;
}

function RowComponent({ row, rowIndex, onUpdate, onDelete, onAddRow, dragHandleProps, copiedBlock, onCopy, onClearCopy }: RowComponentProps) {
  const handleBlockUpdate = (blockId: string, content: string, attachments?: string) => {
    const updatedBlocks = row.blocks.map(block => 
      block.id === blockId 
        ? { ...block, content, attachments }
        : block
    );
    onUpdate(row.id, updatedBlocks);
  };

  const handleBlockDelete = (blockId: string) => {
    const updatedBlocks = row.blocks.filter(block => block.id !== blockId);
    if (updatedBlocks.length === 0) {
      // If no blocks left, delete the row
      onDelete(row.id);
    } else {
      onUpdate(row.id, updatedBlocks);
    }
  };

  const handleInsertLeft = (blockId: string) => {
    const blockIndex = row.blocks.findIndex(b => b.id === blockId);
    
    let blockToInsert: Block;
    if (copiedBlock) {
      // Paste the copied block (create a new copy with new ID)
      blockToInsert = {
        ...copiedBlock,
        id: generateId(),
      };
      onClearCopy();
    } else {
      // Insert a new empty block
      blockToInsert = {
        id: generateId(),
        type: 'text',
        content: '',
      };
    }
    
    const updatedBlocks = [
      ...row.blocks.slice(0, blockIndex),
      blockToInsert,
      ...row.blocks.slice(blockIndex)
    ];
    onUpdate(row.id, updatedBlocks);
  };

  const handleInsertRight = (blockId: string) => {
    const blockIndex = row.blocks.findIndex(b => b.id === blockId);
    
    let blockToInsert: Block;
    if (copiedBlock) {
      // Paste the copied block (create a new copy with new ID)
      blockToInsert = {
        ...copiedBlock,
        id: generateId(),
      };
      onClearCopy();
    } else {
      // Insert a new empty block
      blockToInsert = {
        id: generateId(),
        type: 'text',
        content: '',
      };
    }
    
    const updatedBlocks = [
      ...row.blocks.slice(0, blockIndex + 1),
      blockToInsert,
      ...row.blocks.slice(blockIndex + 1)
    ];
    onUpdate(row.id, updatedBlocks);
  };

  const handleTypeChange = (blockId: string, type: BlockType) => {
    const updatedBlocks = row.blocks.map(block => 
      block.id === blockId 
        ? { ...block, type, content: type === 'file' ? '' : block.content }
        : block
    );
    onUpdate(row.id, updatedBlocks);
  };

  const handleBlockDragEnd = (result: DropResult) => {
    if (!result.destination) return;

    const sourceIndex = result.source.index;
    const destinationIndex = result.destination.index;

    if (sourceIndex === destinationIndex) return;

    const newBlocks = Array.from(row.blocks);
    const [removed] = newBlocks.splice(sourceIndex, 1);
    newBlocks.splice(destinationIndex, 0, removed);

    onUpdate(row.id, newBlocks);
  };

  return (
    <div className="flex gap-2 items-start -mt-[1px] -ml-7 -mr-8">
      {/* Left side: Drag handle */}
      <div 
        style={{ display: 'flex', alignItems: 'center', paddingTop: '8px' }}
        {...dragHandleProps}
      >
        <IconGripVertical size={20} style={{ cursor: 'grab', color: '#999' }} />
      </div>
      
      {/* Middle: Blocks */}
      <div style={{ flex: 1 }}>
        <DragDropContext onDragEnd={handleBlockDragEnd}>
          <Droppable droppableId={`blocks-${row.id}`} direction="horizontal">
            {(provided) => (
              <div
                ref={provided.innerRef}
                {...provided.droppableProps}
                style={{
                  display: 'grid',
                  gridTemplateColumns: `repeat(${row.blocks.length}, 1fr)`,
                  gap: '0px',
                }}
              >
                {row.blocks.map((block, index) => (
                  <Draggable key={block.id} draggableId={block.id} index={index}>
                    {(provided, snapshot) => (
                      <div
                        ref={provided.innerRef}
                        {...provided.draggableProps}
                        style={{
                          ...provided.draggableProps.style,
                          opacity: snapshot.isDragging ? 0.8 : 1,
                        }}
                        className="border bg-gray-50 -ml-[1px]"
                      >
                        <BlockComponent
                          block={block}
                          rowId={row.id}
                          blockIndex={index}
                          onUpdate={handleBlockUpdate}
                          onDelete={handleBlockDelete}
                          onInsertLeft={handleInsertLeft}
                          onInsertRight={handleInsertRight}
                          onTypeChange={handleTypeChange}
                          dragHandleProps={provided.dragHandleProps}
                          onCopy={onCopy}
                          hasCopiedBlock={!!copiedBlock}
                        />
                      </div>
                    )}
                  </Draggable>
                ))}
                {provided.placeholder}
              </div>
            )}
          </Droppable>
        </DragDropContext>
      </div>

      {/* Right side: Add Block and Delete buttons */}
      <div className="flex flex-col items-center gap-1 pt-2">
        <Menu shadow="md" width={200}>
          <Menu.Target>
            <ActionIcon
              variant="subtle"
              size="sm"
              title="Add Block"
            >
              <IconPlus size={16} />
            </ActionIcon>
          </Menu.Target>
          <Menu.Dropdown>
            <Menu.Label>Add Block Type</Menu.Label>
            <Menu.Item 
              leftSection={<IconEdit size={14} />}
              onClick={() => {
                const newBlock: Block = {
                  id: generateId(),
                  type: 'text',
                  content: '',
                };
                onUpdate(row.id, [...row.blocks, newBlock]);
              }}
            >
              Text Editor
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconCode size={14} />}
              onClick={() => {
                const newBlock: Block = {
                  id: generateId(),
                  type: 'code',
                  content: '',
                };
                onUpdate(row.id, [...row.blocks, newBlock]);
              }}
            >
              Code Block
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconTable size={14} />}
              onClick={() => {
                const newBlock: Block = {
                  id: generateId(),
                  type: 'table',
                  content: '',
                };
                onUpdate(row.id, [...row.blocks, newBlock]);
              }}
            >
              Table Builder
            </Menu.Item>
            <Menu.Item 
              leftSection={<IconFile size={14} />}
              onClick={() => {
                const newBlock: Block = {
                  id: generateId(),
                  type: 'file',
                  content: '',
                };
                onUpdate(row.id, [...row.blocks, newBlock]);
              }}
            >
              File Uploader
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
        <ActionIcon
          variant="subtle"
          color="red"
          onClick={() => onDelete(row.id)}
          size="sm"
        >
          <IconTrash size={16} />
        </ActionIcon>
      </div>
    </div>
  );
}

// Main PageBuilder component
export function PageBuilder({ rows, onChange }: PageBuilderProps) {
  const [copiedBlock, setCopiedBlock] = useState<Block | null>(null);

  const handleRowUpdate = (rowId: string, blocks: Block[]) => {
    const updatedRows = rows.map(row =>
      row.id === rowId ? { ...row, blocks } : row
    );
    onChange(updatedRows);
  };

  const handleRowDelete = (rowId: string) => {
    const updatedRows = rows.filter(row => row.id !== rowId);
    onChange(updatedRows);
  };

  const handleAddRow = () => {
    const newRow: Row = {
      id: generateId(),
      blocks: [{
        id: generateId(),
        type: 'text',
        content: '',
      }],
    };
    onChange([...rows, newRow]);
  };

  const handleCopyBlock = (block: Block) => {
    setCopiedBlock(block);
  };

  const handleClearCopy = () => {
    setCopiedBlock(null);
  };

  const handleRowDragEnd = (result: DropResult) => {
    if (!result.destination) return;

    const sourceIndex = result.source.index;
    const destinationIndex = result.destination.index;

    if (sourceIndex === destinationIndex) return;

    const newRows = Array.from(rows);
    const [removed] = newRows.splice(sourceIndex, 1);
    newRows.splice(destinationIndex, 0, removed);

    onChange(newRows);
  };

  return (
    <Box>
      <DragDropContext onDragEnd={handleRowDragEnd}>
        <Droppable droppableId="rows">
          {(provided) => (
            <div ref={provided.innerRef} {...provided.droppableProps}>
              {rows.map((row, index) => (
                <Draggable key={row.id} draggableId={row.id} index={index}>
                  {(provided, snapshot) => (
                    <div
                      ref={provided.innerRef}
                      {...provided.draggableProps}
                      style={{
                        ...provided.draggableProps.style,
                        opacity: snapshot.isDragging ? 0.8 : 1,
                        marginBottom: '0px',
                      }}
                    >
                      <RowComponent
                        row={row}
                        rowIndex={index}
                        onUpdate={handleRowUpdate}
                        onDelete={handleRowDelete}
                        onAddRow={handleAddRow}
                        dragHandleProps={provided.dragHandleProps}
                        copiedBlock={copiedBlock}
                        onCopy={handleCopyBlock}
                        onClearCopy={handleClearCopy}
                      />
                    </div>
                  )}
                </Draggable>
              ))}
              {provided.placeholder}
            </div>
          )}
        </Droppable>
      </DragDropContext>

      {rows.length === 0 && (
        <Box p="xl" style={{ textAlign: 'center', border: '2px dashed #ccc', borderRadius: 8 }}>
          <Text c="dimmed" mb="md">No rows yet. Click "Add Row" to get started.</Text>
          <Button onClick={handleAddRow} leftSection={<IconPlus size={14} />}>
            Add Your First Row
          </Button>
        </Box>
      )}

      {rows.length > 0 && (
        <Box className="mt-0">
          <Button
            size="sm"
            variant="subtle"
            leftSection={<IconPlus size={14} />}
            onClick={handleAddRow}
            fullWidth
            radius={0}
          >
            Add Section
          </Button>
        </Box>
      )}
    </Box>
  );
}
