import { useState, useEffect, useMemo, useCallback, memo, useRef } from 'react';
import { Box, Button, ActionIcon, Menu, Textarea, Paper, Group, Text, Tabs } from '@mantine/core';
import { IconGripVertical, IconPlus, IconTrash, IconCode, IconTable, IconFile, IconEdit, IconChevronLeft, IconChevronRight, IconGripHorizontal, IconCopy, IconDotsVertical, IconPhoto, IconColumnInsertRight, IconRowInsertBottom } from '@tabler/icons-react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { RichTextEditor } from '@mantine/tiptap';
import { useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { FileUploader } from './FileUploader';
import { Row, Block, BlockType, FileData } from '../types/types';
import { getConfig } from '../utils';
import { IMAGE_MIME_TYPE } from '@mantine/dropzone';

type PageBuilderProps = {
  rows: Row[];
  onChange: (rows: Row[]) => void;
}

// Generate unique ID
const generateId = () => `id-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

// Helper function to parse attachments string and convert to FileData objects
const parseAttachmentsToFileData = (attachments: string | undefined): FileData[] => {
  if (!attachments) return [];
  
  return attachments.split(',').map((entry, index) => {
    let filename = '';
    
    if (entry.startsWith('NEW::')) {
      filename = entry.replace('NEW::', '');
    } else if (entry.startsWith('EXISTING::')) {
      filename = entry.replace('EXISTING::', '');
    } else {
      return null;
    }
    
    // Both NEW and EXISTING files are on the server, so treat as existing for display
    const isExisting = true;
    
    // Build the file path/URL
    const fileUrl = getConfig().wwwroot + '/local/announcements2/upload.php?view=1&fileid=' + encodeURIComponent(filename) + '&sesskey=' + getConfig().sesskey;
    
    // Determine mimetype from filename extension
    const getMimeType = (filename: string): string => {
      const ext = filename.toLowerCase().split('.').pop() || '';
      const mimeTypes: Record<string, string> = {
        'jpg': 'image/jpeg',
        'jpeg': 'image/jpeg',
        'png': 'image/png',
        'gif': 'image/gif',
        'webp': 'image/webp',
        'svg': 'image/svg+xml',
        'pdf': 'application/pdf',
        'doc': 'application/msword',
        'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      };
      return mimeTypes[ext] || 'application/octet-stream';
    };
    
    return {
      index: index,
      displayname: filename,
      file: null,
      progress: 100,
      started: true,
      completed: true,
      removed: false,
      serverfilename: filename,
      existing: isExisting,
      key: filename,
      fileid: filename,
      path: fileUrl,
      mimetype: getMimeType(filename),
    } as FileData;
  }).filter(Boolean) as FileData[];
};

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
  totalBlocks: number;
}

const BlockComponent = memo(function BlockComponent({ 
  block, 
  onUpdate, 
  onDelete, 
  onInsertLeft, 
  onInsertRight,
  onTypeChange,
  dragHandleProps,
  onCopy,
  hasCopiedBlock = false,
  totalBlocks
}: BlockComponentProps) {
  // Use ref to track if update is from editor itself
  const isEditorUpdateRef = useRef(false);
  const lastContentRef = useRef(block.content);

  const handleEditorUpdate = useCallback(({ editor }: { editor: any }) => {
    isEditorUpdateRef.current = true;
    const html = editor.getHTML();
    if (html !== lastContentRef.current) {
      lastContentRef.current = html;
      onUpdate(block.id, html);
    }
    // Reset flag after a short delay to allow state updates
    setTimeout(() => {
      isEditorUpdateRef.current = false;
    }, 0);
  }, [block.id, onUpdate]);

  const editor = useEditor({
    extensions: [StarterKit],
    content: block.type === 'text' ? block.content : '',
    onUpdate: handleEditorUpdate,
    immediatelyRender: false,
  });

  // Update editor content when block content changes externally (but not from editor itself)
  useEffect(() => {
    if (editor && block.type === 'text' && !isEditorUpdateRef.current) {
      const currentContent = editor.getHTML();
      if (currentContent !== block.content) {
        lastContentRef.current = block.content;
        editor.commands.setContent(block.content, { parseOptions: { preserveWhitespace: false } });
      }
    }
  }, [block.content, block.type, editor]);

  // Memoize existing files to prevent recreating array on every render
  const existingFiles = useMemo(() => {
    if (block.type === 'file') {
      return parseAttachmentsToFileData(block.attachments);
    }
    return [];
  }, [block.type, block.attachments]);

  // Memoize handlers to prevent re-renders
  const handleCodeChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    onUpdate(block.id, e.target.value);
  }, [block.id, onUpdate]);

  const handleTableChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    onUpdate(block.id, e.target.value);
  }, [block.id, onUpdate]);

  const handleFileUpdate = useCallback((value: string) => {
    onUpdate(block.id, '', value);
  }, [block.id, onUpdate]);

  // Memoize styles
  const contentStyle = useMemo(() => ({ 
    cursor: 'text' as const,
    wordBreak: 'break-word' as const,
    overflowWrap: 'break-word' as const,
    overflow: 'hidden' as const
  }), []);

  const codeTextareaStyles = useMemo(() => ({
    input: {
      fontFamily: 'monospace',
      border: 'none',
    },
  }), []);

  const tableBoxStyle = useMemo(() => ({ border: '1px dashed #ccc', borderRadius: 4 }), []);
  const previewStyle = useMemo(() => ({ padding: '16px' }), []);

  const renderBlockContent = useMemo(() => {
    switch (block.type) {
      case 'text':
        if (!editor) {
          return <Box p="md">Loading editor...</Box>;
        }
        return (
          <RichTextEditor editor={editor} className="!rounded-none !border-none">
            <RichTextEditor.Toolbar className="!border-none !rounded-none !pb-0 !pr-16 !m-0">
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
            <RichTextEditor.Content style={contentStyle} />
          </RichTextEditor>
        );
      case 'code':
        return (
          <Tabs defaultValue="edit">
            <Tabs.List className='pt-1'>
              <Tabs.Tab value="edit">Edit</Tabs.Tab>
              <Tabs.Tab value="preview">Preview</Tabs.Tab>
            </Tabs.List>
            <Tabs.Panel value="edit">
              <Textarea
                value={block.content}
                onChange={handleCodeChange}
                placeholder="Enter code here..."
                autosize
                minRows={4}
                styles={codeTextareaStyles}
              />
            </Tabs.Panel>
            <Tabs.Panel value="preview">
              <div 
                dangerouslySetInnerHTML={{ __html: block.content || '' }} 
                style={previewStyle} 
              />
            </Tabs.Panel>
          </Tabs>
        );
      case 'table':
        return (
          <Box p="md" style={tableBoxStyle}>
            <Textarea
              value={block.content}
              onChange={handleTableChange}
              placeholder="Table builder - Enter table HTML or markdown here..."
              minRows={5}
            />
          </Box>
        );
      case 'file':
        return (
          <FileUploader
            label="Upload image"
            desc="Drag and drop image here..."
            maxFiles={1}
            maxSize={10}
            existingfiles={existingFiles}
            setState={handleFileUpdate}
            showPreview={true}
            mimeTypes={IMAGE_MIME_TYPE}
          />
        );
      default:
        return null;
    }
  }, [block.type, block.content, editor, existingFiles, handleCodeChange, handleTableChange, handleFileUpdate, contentStyle, codeTextareaStyles, tableBoxStyle, previewStyle]);

  return (
    <div className="relative" >
      <Group 
        justify="space-between" 
        gap="0" 
        mb="xs" 
        style={{ position: 'absolute', top: 8, right: 8, zIndex: 10 }}
        className="bg-white/50 backdrop-blur-sm rounded-md"
      >
  
        <ActionIcon variant="subtle" {...dragHandleProps} className={totalBlocks > 1 ? 'opacity-100' : 'opacity-0 hidden'}>
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
              leftSection={<IconPhoto size={14} />}
              onClick={() => onTypeChange(block.id, 'file')}
              disabled={block.type === 'file'}
            >
              Image
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
      <div className='border-b'>
        {renderBlockContent}
      </div>
    </div>
  );
}, (prevProps, nextProps) => {
  // Custom comparison function for React.memo
  return (
    prevProps.block.id === nextProps.block.id &&
    prevProps.block.type === nextProps.block.type &&
    prevProps.block.content === nextProps.block.content &&
    prevProps.block.attachments === nextProps.block.attachments &&
    prevProps.totalBlocks === nextProps.totalBlocks &&
    prevProps.hasCopiedBlock === nextProps.hasCopiedBlock
  );
});

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
  totalRows: number;
}

const RowComponent = memo(function RowComponent({ row, rowIndex, onUpdate, onDelete, onAddRow, dragHandleProps, copiedBlock, onCopy, onClearCopy, totalRows }: RowComponentProps) {
  const handleBlockUpdate = useCallback((blockId: string, content: string, attachments?: string) => {
    const updatedBlocks = row.blocks.map(block => 
      block.id === blockId 
        ? { ...block, content, attachments }
        : block
    );
    onUpdate(row.id, updatedBlocks);
  }, [row.blocks, row.id, onUpdate]);

  const handleBlockDelete = useCallback((blockId: string) => {
    const updatedBlocks = row.blocks.filter(block => block.id !== blockId);
    if (updatedBlocks.length === 0) {
      // If no blocks left, delete the row
      onDelete(row.id);
    } else {
      onUpdate(row.id, updatedBlocks);
    }
  }, [row.blocks, row.id, onDelete, onUpdate]);

  const handleInsertLeft = useCallback((blockId: string) => {
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
  }, [row.blocks, row.id, copiedBlock, onClearCopy, onUpdate]);

  const handleInsertRight = useCallback((blockId: string) => {
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
  }, [row.blocks, row.id, copiedBlock, onClearCopy, onUpdate]);

  const handleTypeChange = useCallback((blockId: string, type: BlockType) => {
    const updatedBlocks = row.blocks.map(block => 
      block.id === blockId 
        ? { ...block, type, content: type === 'file' ? '' : block.content }
        : block
    );
    onUpdate(row.id, updatedBlocks);
  }, [row.blocks, row.id, onUpdate]);

  const handleBlockDragEnd = useCallback((result: DropResult) => {
    if (!result.destination) return;

    const sourceIndex = result.source.index;
    const destinationIndex = result.destination.index;

    if (sourceIndex === destinationIndex) return;

    const newBlocks = Array.from(row.blocks);
    const [removed] = newBlocks.splice(sourceIndex, 1);
    newBlocks.splice(destinationIndex, 0, removed);

    onUpdate(row.id, newBlocks);
  }, [row.blocks, row.id, onUpdate]);

  return (
    <div className={`flex gap-2 items-start -mt-[1px] -ml-7 -mr-8`}>
      {/* Left side: Drag handle */}
      <div 
        style={{ display: 'flex', alignItems: 'center', paddingTop: '8px' }}
        {...dragHandleProps}
        className={totalRows > 1 ? 'opacity-100' : 'opacity-0 hidden'}
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
                        className="border-t border-l border-r bg-gray-50 -ml-[1px]"
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
                          totalBlocks={row.blocks.length}
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
              <IconColumnInsertRight size={16} />
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
              leftSection={<IconPhoto size={14} />}
              onClick={() => {
                const newBlock: Block = {
                  id: generateId(),
                  type: 'file',
                  content: '',
                };
                onUpdate(row.id, [...row.blocks, newBlock]);
              }}
            >
              Image
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
        <ActionIcon
          variant="subtle"
          color="red"
          onClick={() => onDelete(row.id)}
          size="sm"
          title="Delete Row"
        >
          <IconTrash size={16} />
        </ActionIcon>
      </div>
    </div>
  );
}, (prevProps, nextProps) => {
  // Custom comparison function for React.memo
  if (prevProps.row.id !== nextProps.row.id) return false;
  if (prevProps.row.blocks.length !== nextProps.row.blocks.length) return false;
  if (prevProps.totalRows !== nextProps.totalRows) return false;
  if (prevProps.copiedBlock?.id !== nextProps.copiedBlock?.id) return false;
  
  // Deep compare blocks
  for (let i = 0; i < prevProps.row.blocks.length; i++) {
    const prevBlock = prevProps.row.blocks[i];
    const nextBlock = nextProps.row.blocks[i];
    if (
      prevBlock.id !== nextBlock.id ||
      prevBlock.type !== nextBlock.type ||
      prevBlock.content !== nextBlock.content ||
      prevBlock.attachments !== nextBlock.attachments
    ) {
      return false;
    }
  }
  
  return true;
});

// Main PageBuilder component
export function PageBuilder({ rows, onChange }: PageBuilderProps) {
  const [copiedBlock, setCopiedBlock] = useState<Block | null>(null);

  const handleRowUpdate = useCallback((rowId: string, blocks: Block[]) => {
    const updatedRows = rows.map(row =>
      row.id === rowId ? { ...row, blocks } : row
    );
    onChange(updatedRows);
  }, [rows, onChange]);

  const handleRowDelete = useCallback((rowId: string) => {
    const updatedRows = rows.filter(row => row.id !== rowId);
    onChange(updatedRows);
  }, [rows, onChange]);

  const handleAddRow = useCallback(() => {
    const newRow: Row = {
      id: generateId(),
      blocks: [{
        id: generateId(),
        type: 'text',
        content: '',
      }],
    };
    onChange([...rows, newRow]);
  }, [rows, onChange]);

  const handleCopyBlock = useCallback((block: Block) => {
    setCopiedBlock(block);
  }, []);

  const handleClearCopy = useCallback(() => {
    setCopiedBlock(null);
  }, []);

  const handleRowDragEnd = useCallback((result: DropResult) => {
    if (!result.destination) return;

    const sourceIndex = result.source.index;
    const destinationIndex = result.destination.index;

    if (sourceIndex === destinationIndex) return;

    const newRows = Array.from(rows);
    const [removed] = newRows.splice(sourceIndex, 1);
    newRows.splice(destinationIndex, 0, removed);

    onChange(newRows);
  }, [rows, onChange]);

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
                        totalRows={rows.length}
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
          <Button onClick={handleAddRow} leftSection={<IconRowInsertBottom size={14} />}>
            Add Your First Row
          </Button>
        </Box>
      )}

      {rows.length > 0 && (
        <Box className="mt-2 xtext-center">
          <Button
            size="compact-sm"
            variant="subtle"
            leftSection={<IconRowInsertBottom size={14} />}
            onClick={handleAddRow}
          >
            Add Section
          </Button>
        </Box>
      )}
    </Box>
  );
}
