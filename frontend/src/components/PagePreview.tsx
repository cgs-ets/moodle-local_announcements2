import { Row, Block } from "../types/types"
import { Box, Text, Group } from '@mantine/core';
import { IconFile, IconPhoto, IconFileTypePdf, IconFileWord } from '@tabler/icons-react';
import { getConfig } from "../utils";

type Props = {
  rows: Row[],
}

// Helper function to check if a file is an image
const isImageFile = (filename: string): boolean => {
  const imageExtensions = [".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg"];
  return imageExtensions.some(ext => filename.toLowerCase().endsWith(ext));
};

// Helper function to get file icon
const getFileIcon = (filename: string) => {
  if (filename.toLowerCase().includes(".doc")) {
    return <IconFileWord size={24} />;
  } else if (filename.toLowerCase().includes(".pdf")) {
    return <IconFileTypePdf size={24} />;
  } else if (isImageFile(filename)) {
    return <IconPhoto size={24} />;
  }
  return <IconFile size={24} />;
};

// Helper function to parse attachments string and get file info
const parseAttachments = (attachments: string | undefined): Array<{ filename: string; isNew: boolean }> => {
  if (!attachments) return [];
  
  return attachments.split(',').map(entry => {
    if (entry.startsWith('NEW::')) {
      return { filename: entry.replace('NEW::', ''), isNew: true };
    } else if (entry.startsWith('EXISTING::')) {
      return { filename: entry.replace('EXISTING::', ''), isNew: false };
    }
    return null;
  }).filter(Boolean) as Array<{ filename: string; isNew: boolean }>;
};

// Helper function to build image URL
const getImageUrl = (filename: string): string => {
  return getConfig().wwwroot + '/local/announcements2/upload.php?view=1&fileid=' + encodeURIComponent(filename) + '&sesskey=' + getConfig().sesskey;
};

// Block renderer component
function BlockRenderer({ block }: { block: Block }) {
  switch (block.type) {
    case 'text':
      // Render HTML content for text blocks
      return (
        <div 
          dangerouslySetInnerHTML={{ __html: block.content || '' }}
          style={{ padding: '16px' }}
        />
      );
    
    case 'code':
      // Render code block with monospace font
      return (
        <Box p="md" style={{ fontFamily: 'monospace', whiteSpace: 'pre-wrap', backgroundColor: '#f5f5f5' }}>
          <Text size="sm">{block.content || ''}</Text>
        </Box>
      );
    
    case 'table':
      // Render table HTML
      return (
        <div 
          dangerouslySetInnerHTML={{ __html: block.content || '' }}
          style={{ padding: '16px' }}
        />
      );
    
    case 'file':
      // Parse attachments and render file or image
      const files = parseAttachments(block.attachments);
      
      if (files.length === 0) {
        return (
          <Box p="md" style={{ textAlign: 'center', color: '#999' }}>
            <Text size="sm" c="dimmed">No file attached</Text>
          </Box>
        );
      }
      
      // For file blocks, we expect maxFiles=1, so take the first file
      const file = files[0];
      
      if (isImageFile(file.filename)) {
        // Render image
        const imageUrl = getImageUrl(file.filename);
        return (
          <div style={{ width: '100%' }}>
            <img 
              src={imageUrl} 
              alt={file.filename}
              style={{ 
                width: '100%', 
                height: 'auto', 
                display: 'block',
                maxHeight: '500px',
                objectFit: 'contain'
              }}
            />
          </div>
        );
      } else {
        // Render file icon and name
        return (
          <Box p="md" style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            {getFileIcon(file.filename)}
            <Text size="sm" style={{ flex: 1 }}>{file.filename}</Text>
          </Box>
        );
      }
    
    default:
      return null;
  }
}

export function PagePreview({ rows }: Props) {
  if (rows.length === 0) {
    return (
      <Box p="xl" style={{ textAlign: 'center' }}>
        <Text c="dimmed">No content to preview</Text>
      </Box>
    );
  }

  return (
    <div className="flex flex-col gap-0 pt-1">
      {rows.map((row) => (
        <div key={row.id} className="flex gap-0 -mt-[1px]">
          {row.blocks.map((block) => (
            <div key={block.id} className="flex-1 -ml-[1px] border">
              <BlockRenderer block={block} />
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}