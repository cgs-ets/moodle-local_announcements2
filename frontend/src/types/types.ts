export type User = {
  un: string,
  fn: string,
  ln: string
}

export type DecordatedUser = {
  value: { un: string, fn: string, ln: string },
  label: string,
  username: string,
  image: string,
  year?: string,
}

export type Course = {
  id: number,
  idnumber: string,
  fullname: string,
}


export type Announcement = {
  id: number;
  username: string;
  subject: string;
  message: string;
  timecreated: string;
  timemodified: string;
  timestart: string;
  endenabled: boolean;
  timeend: string;
  deleted: boolean;
  forcesend: boolean;
  attachments: string;
  existingattachments: FileData[];
  uploadedimages: string;
  impersonate: User[];
  audiences: Audience[];
}

export type FileData = {
  index: number,
  displayname: string,
  file: File | null,
  progress: number,
  started: boolean,
  completed: boolean,
  removed: boolean,
  serverfilename: string,
  existing: boolean,
  key: string,
  fileid: string,
  path: string,
  mimetype: string,
}


export type Parent =
  User & {
    response?: number,
  }
  
export type Student =
  User & {
    permission?: number,
    parents?: Parent[],
    year: string,
  }

export type Taglist = {
  id: string,
  name: string,
}

export type BlockType = 'text' | 'code' | 'table' | 'file';

export type Block = {
  id: string;
  type: BlockType;
  content: string;
  // For file blocks, store attachment data
  attachments?: string;
}

export type Row = {
  id: string;
  blocks: Block[];
}

export type Errors = {
  submit?: string;
  subject?: string;
  message?: string;
  timecreated?: string;
  timemodified?: string;
  timestart?: string;
  endenabled?: string;
  timeend?: string;
  deleted?: string;
  forcesend?: string;
  attachments?: string;
  existingattachments?: string;
  uploadedimages?: string;
  impersonate?: string;
  audiences?: string;
}

export type Audience = {
  id: string,
  label: string,
  roles?: string[],
  items?: Audience[],
  children?: Audience[],
}